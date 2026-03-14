<?php

declare(strict_types=1);

namespace Brenner\Database;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseManager
{
	private array $profiles;
	private array $connections = [];

	public function __construct(array $profiles)
	{
		$this->profiles = $profiles;
	}

	public function connection(string $name): PDO
	{
		if (!isset($this->connections[$name])) {
			if (!isset($this->profiles[$name])) {
				throw new RuntimeException(sprintf('Database profile "%s" is not configured.', $name));
			}

			$this->connections[$name] = $this->createConnection($this->profiles[$name]);
		}

		return $this->connections[$name];
	}

	private function createConnection(array $profile): PDO
	{
		$driver = strtolower((string) ($profile['driver'] ?? ''));
		$username = $profile['username'] ?? null;
		$password = $profile['password'] ?? null;
		$options = $profile['options'] ?? [];

		$defaults = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];

		try {
			return new PDO(
				$this->buildDsn($driver, $profile),
				is_string($username) ? $username : null,
				is_string($password) ? $password : null,
				$options + $defaults
			);
		} catch (PDOException $exception) {
			throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
		}
	}

	private function buildDsn(string $driver, array $profile): string
	{
		return match ($driver) {
			'mysql' => $this->buildMySqlDsn($profile),
			'pgsql' => $this->buildPgSqlDsn($profile),
			'sqlite' => $this->buildSqliteDsn($profile),
			'sqlsrv' => $this->buildSqlSrvDsn($profile),
			default => throw new RuntimeException(sprintf('Unsupported PDO driver "%s".', $driver)),
		};
	}

	private function buildMySqlDsn(array $profile): string
	{
		$host = (string) ($profile['host'] ?? '127.0.0.1');
		$port = (int) ($profile['port'] ?? 3306);
		$database = (string) ($profile['database'] ?? '');
		$charset = (string) ($profile['charset'] ?? 'utf8mb4');

		return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
	}

	private function buildPgSqlDsn(array $profile): string
	{
		$host = (string) ($profile['host'] ?? '127.0.0.1');
		$port = (int) ($profile['port'] ?? 5432);
		$database = (string) ($profile['database'] ?? '');

		return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
	}

	private function buildSqliteDsn(array $profile): string
	{
		$path = (string) ($profile['path'] ?? '');

		if ($path === '') {
			throw new RuntimeException('SQLite profile requires a "path" value.');
		}

		return 'sqlite:' . $path;
	}

	private function buildSqlSrvDsn(array $profile): string
	{
		$host = (string) ($profile['host'] ?? '127.0.0.1');
		$port = (int) ($profile['port'] ?? 1433);
		$database = (string) ($profile['database'] ?? '');

		return sprintf('sqlsrv:Server=%s,%d;Database=%s', $host, $port, $database);
	}
}
