<?php

declare(strict_types=1);

namespace Brenner\Auth;

use Brenner\Support\HTTPException;

final class ClientCredentialStore
{
	private array $clients;

	public function __construct(array $clients)
	{
		$this->clients = $clients;
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

		return [
			'client_id' => $clientID,
			'display_name' => is_string($client['display_name'] ?? null) ? $client['display_name'] : $clientID,
			'scopes' => array_values(array_filter($client['scopes'] ?? [], 'is_string')),
		];
	}
}
