<?php

declare(strict_types=1);

namespace Brenner\Auth;

use Brenner\Support\HTTPException;
use RuntimeException;

final class RotatingGUIDSessionStore
{
	private int $sessionTTLSeconds;
	private string $storagePath;

	public function __construct(array $config)
	{
		$this->sessionTTLSeconds = (int) ($config['session_ttl_seconds'] ?? 1800);
		$this->storagePath = (string) ($config['session_storage_path'] ?? dirname(__DIR__, 2) . '/storage/api_sessions');
	}

	public function openSession(string $clientID, array $scopes): array
	{
		$this->ensureStoragePathExists();

		$sessionID = $this->generateGUID();
		$commandGUID = $this->generateGUID();
		$timestamp = time();
		$record = [
			'session_id' => $sessionID,
			'client_id' => $clientID,
			'scopes' => array_values(array_filter($scopes, 'is_string')),
			'sequence' => 0,
			'current_command_hash' => $this->hashValue($commandGUID),
			'previous_command_hash' => null,
			'last_request_hash' => null,
			'last_response' => null,
			'created_at' => $timestamp,
			'updated_at' => $timestamp,
			'expires_at' => $timestamp + $this->sessionTTLSeconds,
		];

		$result = file_put_contents($this->sessionFilePath($sessionID), json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

		if ($result === false) {
			throw new RuntimeException('Unable to create a session file.');
		}

		return $this->buildSessionPayload($record, $commandGUID);
	}

	public function execute(string $sessionID, string $commandGUID, string $requestHash, callable $operation): array
	{
		$this->ensureStoragePathExists();

		$sessionPath = $this->sessionFilePath($sessionID);

		if (!is_file($sessionPath)) {
			throw new HTTPException(401, 'AUTH_SESSION_UNKNOWN', 'Session is invalid or expired.');
		}

		$handle = fopen($sessionPath, 'c+');

		if ($handle === false) {
			throw new RuntimeException('Unable to open the session file.');
		}

		$deleteSessionPath = false;

		try {
			if (!flock($handle, LOCK_EX)) {
				throw new RuntimeException('Unable to lock the session file.');
			}

			$record = $this->readRecord($handle, $sessionID);

			if ((int) ($record['expires_at'] ?? 0) < time()) {
				$deleteSessionPath = true;
				throw new HTTPException(401, 'AUTH_SESSION_EXPIRED', 'Session has expired.');
			}

			$commandHash = $this->hashValue($commandGUID);
			$currentHash = (string) ($record['current_command_hash'] ?? '');
			$previousHash = (string) ($record['previous_command_hash'] ?? '');

			if ($previousHash !== '' && hash_equals($previousHash, $commandHash)) {
				$lastResponse = $record['last_response'] ?? null;
				$lastRequestHash = (string) ($record['last_request_hash'] ?? '');

				if ($lastRequestHash === $requestHash && is_array($lastResponse)) {
					return $lastResponse;
				}

				throw new HTTPException(409, 'AUTH_COMMAND_GUID_ALREADY_USED', 'Command GUID was already used.');
			}

			if ($currentHash === '' || !hash_equals($currentHash, $commandHash)) {
				throw new HTTPException(401, 'AUTH_COMMAND_GUID_INVALID', 'Command GUID is invalid.');
			}

			$nextCommandGUID = $this->generateGUID();
			$record['previous_command_hash'] = $currentHash;
			$record['current_command_hash'] = $this->hashValue($nextCommandGUID);
			$record['sequence'] = (int) ($record['sequence'] ?? 0) + 1;
			$record['updated_at'] = time();
			$record['expires_at'] = time() + $this->sessionTTLSeconds;

			$sessionPayload = $this->buildSessionPayload($record, $nextCommandGUID);
			$clientContext = [
				'session_id' => (string) $record['session_id'],
				'client_id' => (string) ($record['client_id'] ?? ''),
				'scopes' => array_values(array_filter($record['scopes'] ?? [], 'is_string')),
				'sequence' => (int) $record['sequence'],
			];

			$result = $operation($clientContext, $sessionPayload);

			if (!is_array($result) || !isset($result['status_code'], $result['response']) || !is_array($result['response'])) {
				throw new RuntimeException('Authenticated operation did not return the expected response envelope.');
			}

			$result['response']['session'] = $sessionPayload;
			$record['last_request_hash'] = $requestHash;
			$record['last_response'] = $result;
			$this->writeRecord($handle, $record);

			return $result;
		} finally {
			if (is_resource($handle)) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}

			if ($deleteSessionPath) {
				@unlink($sessionPath);
			}
		}
	}

	private function ensureStoragePathExists(): void
	{
		if (is_dir($this->storagePath)) {
			return;
		}

		if (!mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
			throw new RuntimeException(sprintf('Unable to create session storage path: %s', $this->storagePath));
		}
	}

	private function sessionFilePath(string $sessionID): string
	{
		if (preg_match('/^[a-f0-9-]{36}$/i', $sessionID) !== 1) {
			throw new HTTPException(401, 'AUTH_SESSION_INVALID', 'Session ID format is invalid.');
		}

		return rtrim($this->storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . strtolower($sessionID) . '.json';
	}

	private function readRecord($handle, string $sessionID): array
	{
		rewind($handle);
		$json = stream_get_contents($handle);

		if (!is_string($json) || trim($json) === '') {
			throw new HTTPException(401, 'AUTH_SESSION_UNKNOWN', 'Session is invalid or expired.', ['session_id' => $sessionID]);
		}

		$data = json_decode($json, true);

		if (!is_array($data)) {
			throw new RuntimeException(sprintf('Session file for %s is corrupted.', $sessionID));
		}

		return $data;
	}

	private function writeRecord($handle, array $record): void
	{
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			throw new RuntimeException('Unable to encode session record.');
		}

		rewind($handle);
		ftruncate($handle, 0);
		fwrite($handle, $json);
		fflush($handle);
	}

	private function buildSessionPayload(array $record, string $commandGUID): array
	{
		return [
			'session_id' => (string) $record['session_id'],
			'command_guid' => $commandGUID,
			'sequence' => (int) $record['sequence'],
			'expires_at' => gmdate(DATE_ATOM, (int) $record['expires_at']),
		];
	}

	private function hashValue(string $value): string
	{
		return hash('sha256', $value);
	}

	private function generateGUID(): string
	{
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		$hex = bin2hex($data);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}
}
