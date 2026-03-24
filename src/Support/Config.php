<?php

declare(strict_types=1);

namespace Brenner\Support;

use RuntimeException;

final class Config
{
	private array $app;
	private array $auth;
	private array $databases;
	private array $actions;

	public function __construct(array $app, array $auth, array $databases, array $actions)
	{
		$this->app = $app;
		$this->auth = $auth;
		$this->databases = $databases;
		$this->actions = $actions;
	}

	public static function fromDirectory(string $configDirectory): self
	{
		$app = self::loadConfigPair($configDirectory, 'app');
		$auth = self::loadConfigPair($configDirectory, 'auth');
		$databases = self::loadConfigPair($configDirectory, 'databases');
		$actions = array_replace_recursive(
			self::builtInActions(),
			self::loadConfigPair($configDirectory, 'actions')
		);

		return new self($app, $auth, $databases, $actions);
	}

	public function app(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->app;
		}

		return $this->app[$key] ?? $default;
	}

	public function auth(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->auth;
		}

		return $this->auth[$key] ?? $default;
	}

	public function database(string $name): array
	{
		if (!isset($this->databases[$name])) {
			throw new RuntimeException(sprintf('Unknown database profile "%s".', $name));
		}

		return $this->databases[$name];
	}

	public function databases(): array
	{
		return $this->databases;
	}

	public function hasAction(string $name): bool
	{
		return isset($this->actions[$name]);
	}

	public function action(string $name): array
	{
		if (!$this->hasAction($name)) {
			throw new RuntimeException(sprintf('Unknown action "%s".', $name));
		}

		return $this->actions[$name];
	}

	public function actions(): array
	{
		return $this->actions;
	}

	private static function loadFile(string $filePath): array
	{
		if (!is_file($filePath)) {
			throw new RuntimeException(sprintf('Missing config file: %s', $filePath));
		}

		$data = require $filePath;

		if (!is_array($data)) {
			throw new RuntimeException(sprintf('Config file must return an array: %s', $filePath));
		}

		return $data;
	}

	private static function loadConfigPair(string $configDirectory, string $name): array
	{
		$baseFile = $configDirectory . DIRECTORY_SEPARATOR . $name . '.php';
		$localFile = $configDirectory . DIRECTORY_SEPARATOR . $name . '.local.php';

		$config = self::loadFile($baseFile);

		if (is_file($localFile)) {
			$config = array_replace_recursive($config, self::loadFile($localFile));
		}

		return $config;
	}

	private static function builtInActions(): array
	{
		return [
			'AUTH.OPEN_LINK' => [
				'type' => 'session_open',
				'methods' => ['POST'],
				'requires_auth' => false,
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
			],
			'DB.TABLES' => [
				'type' => 'db_tables',
				'methods' => ['POST'],
				'consume_guid' => false,
				'scopes' => ['db.read'],
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
		];
	}
}
