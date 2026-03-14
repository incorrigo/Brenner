<?php

declare(strict_types=1);

namespace Brenner\Auth;

use Brenner\Support\HTTPException;

final class ClientCredentialStore
{
	private array $clientDefaults;
	private array $clients;

	public function __construct(array $authConfigOrClients)
	{
		if (array_key_exists('clients', $authConfigOrClients) || array_key_exists('client_defaults', $authConfigOrClients)) {
			$this->clientDefaults = is_array($authConfigOrClients['client_defaults'] ?? null)
				? $authConfigOrClients['client_defaults']
				: [];
			$this->clients = is_array($authConfigOrClients['clients'] ?? null)
				? $authConfigOrClients['clients']
				: [];
			return;
		}

		// Backward compatibility with the previous constructor shape.
		$this->clientDefaults = [];
		$this->clients = $authConfigOrClients;
	}

	public function authenticate(string $clientID, string $clientSecret): array
	{
		$client = $this->clients[$clientID] ?? null;

		if (!is_array($client)) {
			throw new HTTPException(401, 'AUTH_INVALID_CLIENT', 'Client credentials are invalid.');
		}

		$secretHash = $client['secret_hash'] ?? null;

		if (!is_string($secretHash) || $secretHash === '') {
			throw new HTTPException(500, 'AUTH_CLIENT_CONFIG_INVALID', 'Client credential configuration is invalid.', [
				'client_id' => $clientID,
			]);
		}

		if (!password_verify($clientSecret, $secretHash)) {
			throw new HTTPException(401, 'AUTH_INVALID_CLIENT', 'Client credentials are invalid.');
		}

		$defaultScopes = array_values(array_filter($this->clientDefaults['scopes'] ?? [], 'is_string'));
		$clientScopes = array_values(array_filter($client['scopes'] ?? [], 'is_string'));
		$scopes = $clientScopes !== [] ? $clientScopes : $defaultScopes;
		$defaultDisplayName = is_string($this->clientDefaults['display_name'] ?? null)
			? trim((string) $this->clientDefaults['display_name'])
			: '';
		$displayName = is_string($client['display_name'] ?? null)
			? trim((string) $client['display_name'])
			: '';

		return [
			'client_id' => $clientID,
			'display_name' => $displayName !== '' ? $displayName : ($defaultDisplayName !== '' ? $defaultDisplayName : $clientID),
			'scopes' => $scopes,
		];
	}
}
