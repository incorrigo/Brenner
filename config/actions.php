<?php

declare(strict_types=1);

return [
	'AUTH.OPEN_LINK' => [
		'type' => 'session_open',
		'methods' => ['POST'],
		'requires_auth' => false,
		'description' => 'Exchange client credentials for a rotating single-use command GUID.',
		'params' => [
			'client_id' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 3,
				'max_length' => 100,
			],
			'client_secret' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 8,
				'max_length' => 200,
				'trim' => false,
			],
		],
	],

	'SYSTEM.STATUS' => [
		'type' => 'static',
		'methods' => ['POST'],
		'requires_auth' => false,
		'description' => 'Health check for desktop clients.',
		'payload' => [
			'online' => true,
			'message' => 'Gateway is running.',
			'transport' => 'HTTPS',
			'authentication' => 'Rotating single-use command GUID',
		],
	],

	'LINK.PING' => [
		'type' => 'static',
		'methods' => ['POST'],
		'description' => 'Authenticated protocol probe that does not depend on your database schema.',
		'payload' => [
			'authenticated' => true,
			'message' => 'Rotating link is alive.',
		],
	],

	'LINK.ECHO' => [
		'type' => 'echo',
		'methods' => ['POST'],
		'description' => 'Authenticated echo action used for replay and retry testing.',
		'params' => [
			'nonce' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 200,
			],
		],
	],

	'USERS.LIST' => [
		'type' => 'sql',
		'database' => 'private_mysql',
		'methods' => ['POST'],
		'scopes' => ['users.read'],
		'description' => 'Example read query for a desktop client. Replace the table and columns with your schema.',
		'sql' => 'SELECT id, name, email FROM users ORDER BY id DESC LIMIT :limit',
		'result' => 'all',
		'params' => [
			'limit' => [
				'type' => 'int',
				'default' => 25,
				'min' => 1,
				'max' => 100,
			],
		],
	],

	'USER.BY_ID' => [
		'type' => 'sql',
		'database' => 'private_mysql',
		'methods' => ['POST'],
		'scopes' => ['users.read'],
		'description' => 'Example single-record lookup for a desktop client. Replace the table and columns with your schema.',
		'sql' => 'SELECT id, name, email FROM users WHERE id = :id LIMIT 1',
		'result' => 'one',
		'params' => [
			'id' => [
				'type' => 'int',
				'required' => true,
				'min' => 1,
			],
		],
	],

	/*
	 * Example write action:
	 *
	 * 'USER.CREATE' => [
	 *     'type' => 'sql',
	 *     'database' => 'private_mysql',
	 *     'methods' => ['POST'],
	 *     'scopes' => ['users.write'],
	 *     'description' => 'Insert a new user.',
	 *     'sql' => 'INSERT INTO users (name, email) VALUES (:name, :email)',
	 *     'result' => 'write',
	 *     'params' => [
	 *         'name' => ['type' => 'string', 'required' => true, 'max_length' => 100],
	 *         'email' => ['type' => 'string', 'required' => true, 'max_length' => 190],
	 *     ],
	 * ],
	 */
];
