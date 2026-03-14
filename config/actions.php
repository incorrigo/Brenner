<?php

declare(strict_types=1);

return [
	'system.status' => [
		'type' => 'static',
		'methods' => ['GET'],
		'description' => 'Simple health check that proves the API is online.',
		'payload' => [
			'online' => true,
			'message' => 'Gateway is running.',
		],
	],

	'users.list' => [
		'type' => 'sql',
		'database' => 'private_mysql',
		'methods' => ['GET'],
		'description' => 'Example read query. Replace the table and columns with your schema.',
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

	'user.by_id' => [
		'type' => 'sql',
		'database' => 'private_mysql',
		'methods' => ['GET'],
		'description' => 'Example single-record lookup. Replace the table and columns with your schema.',
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
	 * 'user.create' => [
	 *     'type' => 'sql',
	 *     'database' => 'private_mysql',
	 *     'methods' => ['POST'],
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
