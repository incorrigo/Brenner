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

		$guid = $this->generateGUID();
		$guidHash = $this->hashGUID($guid);
		$timestamp = time();
		$record = [
			'record_type' => 'active',
			'client_id' => $clientID,
			'scopes' => array_values(array_filter($scopes, 'is_string')),
			'sequence' => 0,
			'guid_hash' => $guidHash,
			'last_spent_guid_hash' => null,
			'created_at' => $timestamp,
			'updated_at' => $timestamp,
			'expires_at' => $timestamp + $this->sessionTTLSeconds,
		];

		$this->writeRecordToPath($this->guidFilePath($guidHash), $record);

		return $this->buildGUIDPayload($guid, (int) $record['sequence'], (int) $record['expires_at']);
	}

	public function execute(string $guid, bool $consumeGUID, ?string $requestHash, callable $operation): array
	{
		$this->ensureStoragePathExists();

		$guidHash = $this->hashGUID($guid);
		$guidPath = $this->guidFilePath($guidHash);

		if (!is_file($guidPath)) {
			throw new HTTPException(401, 'AUTH_GUID_INVALID', 'GUID is invalid or expired.');
		}

		$handle = fopen($guidPath, 'c+');

		if ($handle === false) {
			throw new RuntimeException('Unable to open the GUID record file.');
		}

		$deleteCurrentGUIDPath = false;
		$deletePriorSpentGUIDPath = null;

		try {
			if (!flock($handle, LOCK_EX)) {
				throw new RuntimeException('Unable to lock the GUID record file.');
			}

			$record = $this->readRecord($handle, $guidHash);

			if ((int) ($record['expires_at'] ?? 0) < time()) {
				$deleteCurrentGUIDPath = true;
				throw new HTTPException(401, 'AUTH_GUID_EXPIRED', 'GUID has expired.');
			}

			if (($record['record_type'] ?? '') === 'spent') {
				$lastResponse = $record['last_response'] ?? null;
				$lastRequestHash = (string) ($record['last_request_hash'] ?? '');

				if (is_string($requestHash) && $lastRequestHash === $requestHash && is_array($lastResponse)) {
					return $lastResponse;
				}

				throw new HTTPException(409, 'AUTH_GUID_ALREADY_USED', 'GUID was already spent.');
			}

			if (($record['record_type'] ?? '') !== 'active') {
				throw new RuntimeException('GUID record type is invalid.');
			}

			$sequence = (int) ($record['sequence'] ?? 0);
			$expiresAt = time() + $this->sessionTTLSeconds;
			$clientContext = [
				'client_id' => (string) ($record['client_id'] ?? ''),
				'scopes' => array_values(array_filter($record['scopes'] ?? [], 'is_string')),
				'sequence' => $sequence,
				'consume_guid' => $consumeGUID,
			];

			if ($consumeGUID) {
				$nextGUID = $this->generateGUID();
				$nextGUIDHash = $this->hashGUID($nextGUID);
				$nextSequence = $sequence + 1;
				$guidPayload = $this->buildGUIDPayload($nextGUID, $nextSequence, $expiresAt);
				$result = $operation($clientContext, $guidPayload);

				$this->assertOperationResult($result);

				$nextRecord = [
					'record_type' => 'active',
					'client_id' => $record['client_id'],
					'scopes' => $record['scopes'],
					'sequence' => $nextSequence,
					'guid_hash' => $nextGUIDHash,
					'last_spent_guid_hash' => $guidHash,
					'created_at' => $record['created_at'],
					'updated_at' => time(),
					'expires_at' => $expiresAt,
				];
				$this->writeRecordToPath($this->guidFilePath($nextGUIDHash), $nextRecord);

				$deletePriorSpentGUIDPath = is_string($record['last_spent_guid_hash'] ?? null)
					? $this->guidFilePath((string) $record['last_spent_guid_hash'])
					: null;

				$spentRecord = [
					'record_type' => 'spent',
					'client_id' => $record['client_id'],
					'scopes' => $record['scopes'],
					'sequence' => $nextSequence,
					'guid_hash' => $guidHash,
					'last_request_hash' => $requestHash,
					'last_response' => $result,
					'created_at' => $record['created_at'],
					'updated_at' => time(),
					'expires_at' => $expiresAt,
				];
				$this->writeRecord($handle, $spentRecord);

				return $result;
			}

			$record['updated_at'] = time();
			$record['expires_at'] = $expiresAt;
			$guidPayload = $this->buildGUIDPayload($guid, $sequence, $expiresAt);
			$result = $operation($clientContext, $guidPayload);

			$this->assertOperationResult($result);
			$this->writeRecord($handle, $record);

			return $result;
		} finally {
			if (is_resource($handle)) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}

			if ($deleteCurrentGUIDPath) {
				@unlink($guidPath);
			}

			if (is_string($deletePriorSpentGUIDPath) && is_file($deletePriorSpentGUIDPath)) {
				@unlink($deletePriorSpentGUIDPath);
			}
		}
	}

	private function ensureStoragePathExists(): void
	{
		if (is_dir($this->storagePath)) {
			return;
		}

		if (!mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
			throw new RuntimeException(sprintf('Unable to create GUID storage path: %s', $this->storagePath));
		}
	}

	private function guidFilePath(string $guidHash): string
	{
		if (preg_match('/^[a-f0-9]{64}$/', $guidHash) !== 1) {
			throw new RuntimeException('GUID hash format is invalid.');
		}

		return rtrim($this->storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $guidHash . '.json';
	}

	private function readRecord($handle, string $guidHash): array
	{
		rewind($handle);
		$json = stream_get_contents($handle);

		if (!is_string($json) || trim($json) === '') {
			throw new HTTPException(401, 'AUTH_GUID_INVALID', 'GUID is invalid or expired.', ['guid_hash' => $guidHash]);
		}

		$data = json_decode($json, true);

		if (!is_array($data)) {
			throw new RuntimeException(sprintf('GUID record %s is corrupted.', $guidHash));
		}

		return $data;
	}

	private function writeRecord($handle, array $record): void
	{
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			throw new RuntimeException('Unable to encode GUID record.');
		}

		rewind($handle);
		ftruncate($handle, 0);
		fwrite($handle, $json);
		fflush($handle);
	}

	private function writeRecordToPath(string $path, array $record): void
	{
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			throw new RuntimeException('Unable to encode GUID record.');
		}

		$result = file_put_contents($path, $json, LOCK_EX);

		if ($result === false) {
			throw new RuntimeException(sprintf('Unable to write GUID record: %s', $path));
		}
	}

	private function assertOperationResult(mixed $result): void
	{
		if (!is_array($result) || !isset($result['status_code'], $result['response']) || !is_array($result['response'])) {
			throw new RuntimeException('Authenticated operation did not return the expected response envelope.');
		}
	}

	private function buildGUIDPayload(string $guid, int $sequence, int $expiresAt): array
	{
		return [
			'guid' => $guid,
			'guid_sequence' => $sequence,
			'guid_expires_at' => gmdate(DATE_ATOM, $expiresAt),
		];
	}

	private function hashGUID(string $guid): string
	{
		if (preg_match('/^[a-f0-9-]{36}$/i', $guid) !== 1) {
			throw new HTTPException(401, 'AUTH_GUID_INVALID', 'GUID format is invalid.');
		}

		return hash('sha256', strtolower($guid));
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
