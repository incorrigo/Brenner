<?php

declare(strict_types=1);

return [
	'AUTH.OPEN_LINK' => [
		'type' => 'session_open',
		'methods' => ['POST'],
		'requires_auth' => false,
		'description' => 'Exchange client credentials for the first rotating GUID.',
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
		'description' => 'Describe the HTTPS transport and GUID-authentication model.',
		'payload' => [
			'online' => true,
			'message' => 'Gateway is running.',
			'transport' => 'HTTPS',
			'authentication' => 'Rotating GUID with selective consumption',
		],
	],

	'LINK.ECHO' => [
		'type' => 'echo',
		'methods' => ['POST'],
		'consume_guid' => true,
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

	'LINK.INFO' => [
		'type' => 'static',
		'methods' => ['POST'],
		'consume_guid' => false,
		'description' => 'Authenticated, schema-free read probe. Reuses the current GUID.',
		'payload' => [
			'authenticated' => true,
			'mode' => 'guid-reuse',
			'message' => 'Authenticated non-consuming action succeeded.',
		],
	],

	'LINK.REQUIRE_TAG' => [
		'type' => 'static',
		'methods' => ['POST'],
		'consume_guid' => false,
		'description' => 'Authenticated validation probe requiring a non-empty tag. Reuses the current GUID.',
		'params' => [
			'tag' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 64,
			],
		],
		'payload' => [
			'accepted' => true,
			'message' => 'Required tag accepted.',
		],
	],

	'DB.PROFILES' => [
		'type' => 'db_profiles',
		'methods' => ['POST'],
		'consume_guid' => false,
		'scopes' => ['db.read'],
		'description' => 'List configured database profiles this gateway can access.',
	],

	'DB.TABLES' => [
		'type' => 'db_tables',
		'methods' => ['POST'],
		'consume_guid' => false,
		'scopes' => ['db.read'],
		'description' => 'List table names in the selected schema (MySQL/MariaDB).',
		'params' => [
			'database' => [
				'type' => 'string',
				'nullable' => true,
				'min_length' => 1,
				'max_length' => 100,
			],
			'schema' => [
				'type' => 'string',
				'nullable' => true,
				'max_length' => 128,
			],
		],
	],

	'DB.COLUMNS' => [
		'type' => 'db_columns',
		'methods' => ['POST'],
		'consume_guid' => false,
		'scopes' => ['db.read'],
		'description' => 'List column metadata for one table (MySQL/MariaDB).',
		'params' => [
			'database' => [
				'type' => 'string',
				'nullable' => true,
				'min_length' => 1,
				'max_length' => 100,
			],
			'schema' => [
				'type' => 'string',
				'nullable' => true,
				'max_length' => 128,
			],
			'table' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 128,
			],
		],
	],

	'DB.READ' => [
		'type' => 'db_read',
		'methods' => ['POST'],
		'consume_guid' => false,
		'scopes' => ['db.read'],
		'description' => 'Execute one read-only SQL statement against the selected profile.',
		'params' => [
			'database' => [
				'type' => 'string',
				'nullable' => true,
				'min_length' => 1,
				'max_length' => 100,
			],
			'sql' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 12000,
			],
			'bindings' => [
				'type' => 'map',
				'default' => [],
			],
			'max_rows' => [
				'type' => 'int',
				'default' => 500,
				'min' => 1,
				'max' => 5000,
			],
		],
	],

	'DB.WRITE' => [
		'type' => 'db_write',
		'methods' => ['POST'],
		'consume_guid' => true,
		'scopes' => ['db.write'],
		'description' => 'Execute one write SQL statement against the selected profile.',
		'params' => [
			'database' => [
				'type' => 'string',
				'nullable' => true,
				'min_length' => 1,
				'max_length' => 100,
			],
			'sql' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 12000,
			],
			'bindings' => [
				'type' => 'map',
				'default' => [],
			],
		],
	],

	'DB.EXECUTE' => [
		'type' => 'db_execute',
		'methods' => ['POST'],
		'consume_guid' => true,
		'scopes' => ['db.admin'],
		'description' => 'Execute SQL with full DB-user privileges (data + structure + routines/events/triggers).',
		'params' => [
			'database' => [
				'type' => 'string',
				'nullable' => true,
				'min_length' => 1,
				'max_length' => 100,
			],
			'sql' => [
				'type' => 'string',
				'required' => true,
				'min_length' => 1,
				'max_length' => 120000,
				'trim' => false,
			],
			'bindings' => [
				'type' => 'map',
				'default' => [],
			],
			'max_rows' => [
				'type' => 'int',
				'default' => 500,
				'min' => 1,
				'max' => 10000,
			],
			'all_rowsets' => [
				'type' => 'bool',
				'default' => true,
			],
		],
	],

	/*
	 * Example SQL read action:
	 *
	 * 'DB.EXAMPLE_READ' => [
	 *     'type' => 'sql',
	 *     'database' => 'private_mysql',
	 *     'methods' => ['POST'],
	 *     'scopes' => ['db.read'],
	 *     'consume_guid' => false,
	 *     'description' => 'Read rows from your real schema.',
	 *     'sql' => 'SELECT id, title FROM your_table ORDER BY id DESC LIMIT :limit',
	 *     'result' => 'all',
	 *     'params' => [
	 *         'limit' => ['type' => 'int', 'default' => 25, 'min' => 1, 'max' => 100],
	 *     ],
	 * ],
	 *
	 * Example SQL write action:
	 *
	 * 'DB.EXAMPLE_WRITE' => [
	 *     'type' => 'sql',
	 *     'database' => 'private_mysql',
	 *     'methods' => ['POST'],
	 *     'scopes' => ['db.write'],
	 *     'consume_guid' => true,
	 *     'description' => 'Insert into your real schema.',
	 *     'sql' => 'INSERT INTO your_table (title) VALUES (:title)',
	 *     'result' => 'write',
	 *     'params' => [
	 *         'title' => ['type' => 'string', 'required' => true, 'max_length' => 190],
	 *     ],
	 * ],
	 *
	 * SQL actions with 'result' => 'write' consume and rotate the GUID by default,
	 * even when 'consume_guid' is omitted.
	 */
];
