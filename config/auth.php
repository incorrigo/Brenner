<?php

declare(strict_types=1);

return [
	'session_ttl_seconds' => 1800,
	'session_storage_path' => dirname(__DIR__) . '/storage/api_sessions',
	'client_defaults' => [
		'scopes' => ['db.read'],
	],
	'clients' => [],
];
