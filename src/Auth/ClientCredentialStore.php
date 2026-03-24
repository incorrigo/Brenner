<?php

declare(strict_types=1);

namespace Brenner\Auth;

use Brenner\Support\HTTPException;

final class ClientCredentialStore
{
	private array $clientDefaults;
	private array $clients;
	private array $clientIDLookup;

	public function __construct(array $authConfigOrClients)
	{
		if (array_key_exists('clients', $authConfigOrClients) || array_key_exists('client_defaults', $authConfigOrClients)) {
			$this->clientDefaults = is_array($authConfigOrClients['client_defaults'] ?? null)
				? $authConfigOrClients['client_defaults']
				: [];
			$this->clients = is_array($authConfigOrClients['clients'] ?? null)
				? $authConfigOrClients['clients']
				: [];
			$this->clientIDLookup = $this->buildClientIDLookup($this->clients);
			return;
		}

		// Backward compatibility with the previous constructor shape.
		$this->clientDefaults = [];
		$this->clients = $authConfigOrClients;
		$this->clientIDLookup = $this->buildClientIDLookup($this->clients);
	}

	public function authenticate(string $clientID, string $clientSecret): array
	{
		$resolvedClientID = $this->resolveClientID($clientID);
		$client = $resolvedClientID !== null ? ($this->clients[$resolvedClientID] ?? null) : null;

		if (!is_array($client)) {
			throw new HTTPException(401, 'AUTH_INVALID_CLIENT', 'Client credentials are invalid.');
		}

		$secretHash = $client['secret_hash'] ?? null;

		if (!is_string($secretHash) || $secretHash === '') {
			throw new HTTPException(500, 'AUTH_CLIENT_CONFIG_INVALID', 'Client credential configuration is invalid.', [
				'client_id' => $resolvedClientID,
			]);
		}

		if (!password_verify($clientSecret, $secretHash)) {
			throw new HTTPException(401, 'AUTH_INVALID_CLIENT', 'Client credentials are invalid.');
		}

		$defaultScopes = $this->normalizeScopes(
			$this->clientDefaults['scopes'] ?? [],
			'client_defaults.scopes'
		);
		$clientScopes = array_key_exists('scopes', $client)
			? $this->normalizeScopes($client['scopes'], sprintf('clients.%s.scopes', $resolvedClientID))
			: [];
		$scopes = $clientScopes !== [] ? $clientScopes : $defaultScopes;
		$defaultDisplayName = is_string($this->clientDefaults['display_name'] ?? null)
			? trim((string) $this->clientDefaults['display_name'])
			: '';
		$displayName = is_string($client['display_name'] ?? null)
			? trim((string) $client['display_name'])
			: '';

		return [
			'client_id' => $resolvedClientID,
			'display_name' => $displayName !== '' ? $displayName : ($defaultDisplayName !== '' ? $defaultDisplayName : $resolvedClientID),
			'scopes' => $scopes,
		];
	}

	private function buildClientIDLookup(array $clients): array
	{
		$lookup = [];

		foreach ($clients as $configuredClientID => $_client) {
			if (!is_string($configuredClientID)) {
				continue;
			}

			$canonicalClientID = trim($configuredClientID);
			if ($canonicalClientID === '') {
				continue;
			}

			$normalizedClientID = strtolower($canonicalClientID);
			$existingClientID = $lookup[$normalizedClientID] ?? null;

			if (is_string($existingClientID) && $existingClientID !== $canonicalClientID) {
				throw new HTTPException(
					500,
					'AUTH_CLIENT_CONFIG_INVALID',
					'Client IDs are ambiguous when matched case-insensitively.',
					[
						'client_ids' => [$existingClientID, $canonicalClientID],
					]
				);
			}

			$lookup[$normalizedClientID] = $canonicalClientID;
		}

		return $lookup;
	}

	private function resolveClientID(string $candidateClientID): ?string
	{
		$trimmedClientID = trim($candidateClientID);
		if ($trimmedClientID === '') {
			return null;
		}

		if (array_key_exists($trimmedClientID, $this->clients)) {
			return $trimmedClientID;
		}

		$normalizedClientID = strtolower($trimmedClientID);

		return $this->clientIDLookup[$normalizedClientID] ?? null;
	}

	private function normalizeScopes(mixed $rawScopes, string $configPath): array
	{
		$flattenedScopes = [];
		$this->collectScopes($rawScopes, $flattenedScopes, $configPath);

		$uniqueScopes = [];
		foreach ($flattenedScopes as $scope) {
			if (!in_array($scope, $uniqueScopes, true)) {
				$uniqueScopes[] = $scope;
			}
		}

		return $uniqueScopes;
	}

	private function collectScopes(mixed $candidate, array &$flattenedScopes, string $configPath): void
	{
		if ($candidate === null) {
			return;
		}

		if (is_string($candidate)) {
			$normalizedScope = trim($candidate);
			if ($normalizedScope !== '') {
				$flattenedScopes[] = $normalizedScope;
			}
			return;
		}

		if (is_array($candidate)) {
			foreach ($candidate as $item) {
				$this->collectScopes($item, $flattenedScopes, $configPath);
			}
			return;
		}

		throw new HTTPException(
			500,
			'AUTH_CLIENT_CONFIG_INVALID',
			'Client scope configuration must be a string or an array of strings.',
			[
				'path' => $configPath,
			]
		);
	}
}
