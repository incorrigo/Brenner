<?php

declare(strict_types=1);

namespace Brenner\API;

use Brenner\Auth\ClientCredentialStore;
use Brenner\Auth\RotatingGUIDSessionStore;
use Brenner\Database\DatabaseManager;
use Brenner\Support\Config;
use Brenner\Support\HTTPException;
use PDO;
use PDOStatement;
use Throwable;

final class APIActionRunner
{
	private Config $config;
	private DatabaseManager $databaseManager;
	private ClientCredentialStore $clientCredentialStore;
	private RotatingGUIDSessionStore $guidStore;

	public function __construct(
		Config $config,
		DatabaseManager $databaseManager,
		ClientCredentialStore $clientCredentialStore,
		RotatingGUIDSessionStore $guidStore
	) {
		$this->config = $config;
		$this->databaseManager = $databaseManager;
		$this->clientCredentialStore = $clientCredentialStore;
		$this->guidStore = $guidStore;
	}

	public function run(string $actionName, string $requestMethod, array $input): array
	{
		try {
			$action = $this->config->action($actionName);
		} catch (Throwable $exception) {
			throw new HTTPException(404, 'ACTION_NOT_FOUND', 'Unknown action.', ['action' => $actionName]);
		}

		$allowedMethods = $action['methods'] ?? ['POST'];

		if (!in_array($requestMethod, $allowedMethods, true)) {
			throw new HTTPException(405, 'METHOD_NOT_ALLOWED', 'HTTP method is not allowed for this action.', [
				'action' => $actionName,
				'allowed_methods' => $allowedMethods,
			]);
		}

		$type = (string) ($action['type'] ?? 'sql');
		$params = $this->normalizeParams($action['params'] ?? [], $input);

		return match ($type) {
			'static' => [
				'action' => $actionName,
				'data' => $action['payload'] ?? [],
				'meta' => [
					'method' => $requestMethod,
					'type' => 'static',
				],
			],
			'session_open' => $this->openSession($actionName, $requestMethod, $params),
			'db_profiles' => $this->listDatabaseProfiles($actionName, $requestMethod),
			'db_tables' => $this->listDatabaseTables($actionName, $requestMethod, $params),
			'db_columns' => $this->listDatabaseColumns($actionName, $requestMethod, $params),
			'db_read' => $this->runDatabaseRead($actionName, $requestMethod, $params),
			'db_write' => $this->runDatabaseWrite($actionName, $requestMethod, $params),
			'db_execute' => $this->runDatabaseExecute($actionName, $requestMethod, $params),
			'echo' => [
				'action' => $actionName,
				'data' => [
					'echo' => $params,
					'message' => 'Echo accepted.',
				],
				'meta' => [
					'method' => $requestMethod,
					'type' => 'echo',
				],
			],
			'sql' => $this->runSQLAction($actionName, $requestMethod, $action, $params),
			default => throw new HTTPException(500, 'ACTION_TYPE_UNSUPPORTED', 'Unsupported action type.', [
				'action' => $actionName,
				'type' => $type,
			]),
		};
	}

	private function openSession(string $actionName, string $requestMethod, array $params): array
	{
		$client = $this->clientCredentialStore->authenticate(
			(string) ($params['client_id'] ?? ''),
			(string) ($params['client_secret'] ?? '')
		);
		$guidState = $this->guidStore->openSession($client['client_id'], $client['scopes']);

		return [
			'action' => $actionName,
			'data' => [
				'opened' => true,
				'display_name' => $client['display_name'],
			],
			'client' => [
				'client_id' => $client['client_id'],
				'scopes' => $client['scopes'],
			],
			'guid_state' => $guidState,
			'meta' => [
				'method' => $requestMethod,
				'type' => 'session_open',
			],
		];
	}

	private function listDatabaseProfiles(string $actionName, string $requestMethod): array
	{
		$profiles = array_values(array_filter(
			array_keys($this->config->databases()),
			static fn (mixed $name): bool => is_string($name) && $name !== '' && $name[0] !== '_'
		));
		sort($profiles, SORT_STRING);

		return [
			'action' => $actionName,
			'data' => [
				'profiles' => $profiles,
				'count' => count($profiles),
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_profiles',
			],
		];
	}

	private function listDatabaseTables(string $actionName, string $requestMethod, array $params): array
	{
		$databaseProfile = $this->resolveDatabaseProfile($params['database'] ?? null);
		$connection = $this->databaseManager->connection($databaseProfile);
		$this->assertMySqlDriver($connection, $actionName);
		$schema = $this->resolveSchemaName($connection, $params['schema'] ?? null);

		$statement = $connection->prepare(
			'SELECT table_name FROM information_schema.tables WHERE table_schema = :schema ORDER BY table_name'
		);
		$statement->bindValue(':schema', $schema, PDO::PARAM_STR);
		$statement->execute();

		$rows = $statement->fetchAll();
		$tables = [];
		foreach ($rows as $row) {
			$tables[] = (string) ($row['table_name'] ?? '');
		}

		return [
			'action' => $actionName,
			'data' => [
				'database_profile' => $databaseProfile,
				'schema' => $schema,
				'tables' => $tables,
				'count' => count($tables),
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_tables',
				'database' => $databaseProfile,
			],
		];
	}

	private function listDatabaseColumns(string $actionName, string $requestMethod, array $params): array
	{
		$databaseProfile = $this->resolveDatabaseProfile($params['database'] ?? null);
		$table = (string) ($params['table'] ?? '');
		$connection = $this->databaseManager->connection($databaseProfile);
		$this->assertMySqlDriver($connection, $actionName);
		$schema = $this->resolveSchemaName($connection, $params['schema'] ?? null);

		$statement = $connection->prepare(
			'SELECT column_name, data_type, is_nullable, column_key, column_default, extra
			 FROM information_schema.columns
			 WHERE table_schema = :schema AND table_name = :table
			 ORDER BY ordinal_position'
		);
		$statement->bindValue(':schema', $schema, PDO::PARAM_STR);
		$statement->bindValue(':table', $table, PDO::PARAM_STR);
		$statement->execute();
		$columns = $statement->fetchAll();

		return [
			'action' => $actionName,
			'data' => [
				'database_profile' => $databaseProfile,
				'schema' => $schema,
				'table' => $table,
				'columns' => $columns,
				'count' => count($columns),
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_columns',
				'database' => $databaseProfile,
			],
		];
	}

	private function runDatabaseRead(string $actionName, string $requestMethod, array $params): array
	{
		$databaseProfile = $this->resolveDatabaseProfile($params['database'] ?? null);
		$sql = $this->normalizeSql((string) ($params['sql'] ?? ''));
		$bindings = is_array($params['bindings'] ?? null) ? $params['bindings'] : [];
		$maxRows = (int) ($params['max_rows'] ?? 500);
		$this->assertReadOnlyQuery($sql);

		$connection = $this->databaseManager->connection($databaseProfile);
		$statement = $connection->prepare($sql);
		$this->bindNamedParameters($statement, $bindings);
		$statement->execute();
		$rows = $statement->fetchAll();

		$truncated = false;
		if (count($rows) > $maxRows) {
			$rows = array_slice($rows, 0, $maxRows);
			$truncated = true;
		}

		return [
			'action' => $actionName,
			'data' => [
				'rows' => $rows,
				'row_count' => count($rows),
				'truncated' => $truncated,
				'max_rows' => $maxRows,
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_read',
				'database' => $databaseProfile,
			],
		];
	}

	private function runDatabaseWrite(string $actionName, string $requestMethod, array $params): array
	{
		$databaseProfile = $this->resolveDatabaseProfile($params['database'] ?? null);
		$sql = $this->normalizeSql((string) ($params['sql'] ?? ''));
		$bindings = is_array($params['bindings'] ?? null) ? $params['bindings'] : [];
		$this->assertWriteQuery($sql);

		$connection = $this->databaseManager->connection($databaseProfile);
		$statement = $connection->prepare($sql);
		$this->bindNamedParameters($statement, $bindings);
		$statement->execute();

		return [
			'action' => $actionName,
			'data' => [
				'affected_rows' => $statement->rowCount(),
				'last_insert_id' => $connection->lastInsertId(),
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_write',
				'database' => $databaseProfile,
			],
		];
	}

	private function runDatabaseExecute(string $actionName, string $requestMethod, array $params): array
	{
		$databaseProfile = $this->resolveDatabaseProfile($params['database'] ?? null);
		$sql = $this->normalizeSqlForExecute((string) ($params['sql'] ?? ''));
		$bindings = is_array($params['bindings'] ?? null) ? $params['bindings'] : [];
		$maxRows = (int) ($params['max_rows'] ?? 500);
		$allRowsets = (bool) ($params['all_rowsets'] ?? true);

		$connection = $this->databaseManager->connection($databaseProfile);
		$data = $this->executeGenericSql($connection, $sql, $bindings, $maxRows, $allRowsets);

		return [
			'action' => $actionName,
			'data' => $data + [
				'database_profile' => $databaseProfile,
			],
			'meta' => [
				'method' => $requestMethod,
				'type' => 'db_execute',
				'database' => $databaseProfile,
			],
		];
	}

	private function runSQLAction(string $actionName, string $requestMethod, array $action, array $params): array
	{
		$databaseName = $this->resolveDatabaseProfile($action['database'] ?? null);

		if (trim($databaseName) === '') {
			throw new HTTPException(500, 'DATABASE_PROFILE_MISSING', 'SQL action is missing a database profile.', [
				'action' => $actionName,
			]);
		}

		$connection = $this->databaseManager->connection($databaseName);
		$statement = $connection->prepare((string) ($action['sql'] ?? ''));

		foreach ($params as $name => $value) {
			$statement->bindValue(':' . $name, $value, $this->pdoTypeFor($value));
		}

		$statement->execute();

		return [
			'action' => $actionName,
			'data' => $this->formatResult($connection, $statement, (string) ($action['result'] ?? 'all')),
			'meta' => [
				'method' => $requestMethod,
				'type' => 'sql',
				'database' => $databaseName,
				'params' => $params,
			],
		];
	}

	private function resolveDatabaseProfile(mixed $candidate): string
	{
		if (is_string($candidate) && trim($candidate) !== '') {
			return trim($candidate);
		}

		$defaultProfile = trim((string) $this->config->app('default_database_profile', 'private_mysql'));
		if ($defaultProfile === '') {
			throw new HTTPException(500, 'DATABASE_PROFILE_DEFAULT_MISSING', 'No default database profile is configured.');
		}

		return $defaultProfile;
	}

	private function normalizeParams(array $rules, array $input): array
	{
		$normalized = [];

		foreach ($rules as $name => $rule) {
			$hasValue = array_key_exists($name, $input);
			$value = $hasValue ? $input[$name] : ($rule['default'] ?? null);
			$required = (bool) ($rule['required'] ?? false);
			$nullable = (bool) ($rule['nullable'] ?? false);

			if (!$hasValue && !array_key_exists('default', $rule) && $required) {
				throw new HTTPException(422, 'VALIDATION_MISSING_PARAMETER', sprintf('Missing required parameter "%s".', $name), [
					'parameter' => $name,
				]);
			}

			if ($value === null) {
				if ($required && !$nullable) {
					throw new HTTPException(422, 'VALIDATION_NULL_PARAMETER', sprintf('Parameter "%s" cannot be null.', $name), [
						'parameter' => $name,
					]);
				}

				if ($nullable || array_key_exists('default', $rule)) {
					$normalized[$name] = null;
				}

				continue;
			}

			$normalized[$name] = $this->coerceValue($name, $value, $rule);
		}

		$unknownParameters = array_values(array_diff(array_keys($input), array_keys($rules)));

		if ($unknownParameters !== []) {
			throw new HTTPException(422, 'VALIDATION_UNKNOWN_PARAMETER', 'Request contains unknown parameters.', [
				'unknown_parameters' => $unknownParameters,
			]);
		}

		return $normalized;
	}

	private function normalizeMap(string $name, mixed $value): array
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new HTTPException(422, 'VALIDATION_OBJECT_REQUIRED', sprintf('Parameter "%s" must be an object.', $name), [
				'parameter' => $name,
			]);
		}

		$normalized = [];
		foreach ($value as $key => $item) {
			if (!is_string($key) || $key === '') {
				throw new HTTPException(422, 'VALIDATION_OBJECT_KEY_INVALID', sprintf('Parameter "%s" has an invalid key.', $name), [
					'parameter' => $name,
				]);
			}

			if (!is_scalar($item) && $item !== null) {
				throw new HTTPException(422, 'VALIDATION_OBJECT_VALUE_INVALID', sprintf('Parameter "%s" has an invalid value type.', $name), [
					'parameter' => $name,
					'key' => $key,
				]);
			}

			$normalized[$key] = $item;
		}

		return $normalized;
	}

	private function coerceValue(string $name, mixed $value, array $rule): mixed
	{
		$type = strtolower((string) ($rule['type'] ?? 'string'));

		return match ($type) {
			'int', 'integer' => $this->normalizeInt($name, $value, $rule),
			'float' => $this->normalizeFloat($name, $value, $rule),
			'bool', 'boolean' => $this->normalizeBool($name, $value),
			'string' => $this->normalizeString($name, $value, $rule),
			'map', 'object' => $this->normalizeMap($name, $value),
			default => throw new HTTPException(500, 'PARAMETER_TYPE_UNSUPPORTED', sprintf('Unsupported parameter type "%s".', $type), [
				'parameter' => $name,
				'type' => $type,
			]),
		};
	}

	private function bindNamedParameters(PDOStatement $statement, array $bindings): void
	{
		foreach ($bindings as $name => $value) {
			if (!is_string($name) || $name === '') {
				throw new HTTPException(422, 'VALIDATION_BINDING_NAME_INVALID', 'SQL binding names must be non-empty strings.');
			}

			$parameter = ltrim($name, ':');
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameter) !== 1) {
				throw new HTTPException(422, 'VALIDATION_BINDING_NAME_INVALID', sprintf('SQL binding name "%s" is invalid.', $name), [
					'binding' => $name,
				]);
			}

			if (!is_scalar($value) && $value !== null) {
				throw new HTTPException(422, 'VALIDATION_BINDING_VALUE_INVALID', sprintf('SQL binding "%s" must be a scalar or null.', $name), [
					'binding' => $name,
				]);
			}

			$statement->bindValue(':' . $parameter, $value, $this->pdoTypeFor($value));
		}
	}

	private function normalizeSql(string $sql): string
	{
		$normalized = trim($sql);

		if ($normalized === '') {
			throw new HTTPException(422, 'VALIDATION_SQL_REQUIRED', 'SQL must be provided.');
		}

		if (str_ends_with($normalized, ';')) {
			$normalized = rtrim(substr($normalized, 0, -1));
		}

		if ($normalized === '' || str_contains($normalized, ';')) {
			throw new HTTPException(422, 'VALIDATION_SQL_MULTISTATEMENT_DENIED', 'Exactly one SQL statement is allowed.');
		}

		return $normalized;
	}

	private function normalizeSqlForExecute(string $sql): string
	{
		$normalized = trim($sql);

		if ($normalized === '') {
			throw new HTTPException(422, 'VALIDATION_SQL_REQUIRED', 'SQL must be provided.');
		}

		if (preg_match('/[\x00]/', $normalized) === 1) {
			throw new HTTPException(422, 'VALIDATION_SQL_INVALID', 'SQL contains an invalid null byte.');
		}

		return $normalized;
	}

	private function executeGenericSql(PDO $connection, string $sql, array $bindings, int $maxRows, bool $allRowsets): array
	{
		if ($bindings !== []) {
			$statement = $connection->prepare($sql);
			$this->bindNamedParameters($statement, $bindings);
			$statement->execute();

			return $this->collectStatementResult($statement, $maxRows, $allRowsets);
		}

		try {
			$statement = $connection->prepare($sql);
			$statement->execute();

			return $this->collectStatementResult($statement, $maxRows, $allRowsets);
		} catch (Throwable $prepareException) {
			$affectedRows = $connection->exec($sql);
			if ($affectedRows === false) {
				throw $prepareException;
			}

			return [
				'result_sets' => [],
				'result_set_count' => 0,
				'row_count' => 0,
				'affected_rows' => $affectedRows,
				'truncated' => false,
				'max_rows' => $maxRows,
			];
		}
	}

	private function collectStatementResult(PDOStatement $statement, int $maxRows, bool $allRowsets): array
	{
		$resultSets = [];
		$totalRows = 0;
		$truncated = false;
		$index = 0;
		$affectedRows = $statement->rowCount();

		do {
			$columnCount = $statement->columnCount();
			if ($columnCount > 0) {
				$rows = $statement->fetchAll();
				$rowCount = count($rows);
				$rowSetTruncated = false;
				if ($rowCount > $maxRows) {
					$rows = array_slice($rows, 0, $maxRows);
					$rowCount = $maxRows;
					$rowSetTruncated = true;
					$truncated = true;
				}

				$totalRows += $rowCount;
				$resultSets[] = [
					'index' => $index,
					'column_count' => $columnCount,
					'row_count' => $rowCount,
					'truncated' => $rowSetTruncated,
					'rows' => $rows,
				];
			}

			$index++;
		} while ($allRowsets && $statement->nextRowset());

		return [
			'result_sets' => $resultSets,
			'result_set_count' => count($resultSets),
			'row_count' => $totalRows,
			'affected_rows' => $affectedRows,
			'truncated' => $truncated,
			'max_rows' => $maxRows,
		];
	}

	private function assertReadOnlyQuery(string $sql): void
	{
		$keyword = $this->firstSqlKeyword($sql);
		$allowed = ['select', 'show', 'describe', 'desc', 'explain', 'with'];

		if (!in_array($keyword, $allowed, true)) {
			throw new HTTPException(422, 'DB_READ_ONLY_QUERY_REQUIRED', 'DB.READ only allows read-only SQL statements.', [
				'keyword' => $keyword,
			]);
		}

		if ($keyword === 'with' && preg_match('/\b(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $sql) === 1) {
			throw new HTTPException(422, 'DB_READ_ONLY_QUERY_REQUIRED', 'DB.READ CTE query appears to contain a write operation.');
		}

		if (preg_match('/\binto\s+outfile\b/i', $sql) === 1) {
			throw new HTTPException(422, 'DB_READ_ONLY_QUERY_REQUIRED', 'DB.READ does not allow INTO OUTFILE.');
		}
	}

	private function assertWriteQuery(string $sql): void
	{
		$keyword = $this->firstSqlKeyword($sql);
		$allowed = ['insert', 'update', 'delete', 'replace'];

		if (!in_array($keyword, $allowed, true)) {
			throw new HTTPException(422, 'DB_WRITE_QUERY_REQUIRED', 'DB.WRITE only allows INSERT, UPDATE, DELETE, or REPLACE.', [
				'keyword' => $keyword,
			]);
		}
	}

	private function firstSqlKeyword(string $sql): string
	{
		if (preg_match('/^\s*([A-Za-z_]+)/', $sql, $matches) !== 1) {
			throw new HTTPException(422, 'VALIDATION_SQL_REQUIRED', 'SQL must start with a statement keyword.');
		}

		return strtolower((string) ($matches[1] ?? ''));
	}

	private function assertMySqlDriver(PDO $connection, string $actionName): void
	{
		$driver = strtolower((string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME));
		if ($driver !== 'mysql') {
			throw new HTTPException(422, 'DB_METADATA_DRIVER_UNSUPPORTED', sprintf('%s currently supports MySQL/MariaDB only.', $actionName), [
				'driver' => $driver,
				'action' => $actionName,
			]);
		}
	}

	private function resolveSchemaName(PDO $connection, mixed $configuredSchema): string
	{
		if (is_string($configuredSchema) && trim($configuredSchema) !== '') {
			return trim($configuredSchema);
		}

		$currentDatabase = $connection->query('SELECT DATABASE()')->fetchColumn();
		if (!is_string($currentDatabase) || trim($currentDatabase) === '') {
			throw new HTTPException(422, 'DB_SCHEMA_UNRESOLVED', 'Could not resolve current schema name. Provide params.schema explicitly.');
		}

		return trim($currentDatabase);
	}

	private function normalizeInt(string $name, mixed $value, array $rule): int
	{
		$normalized = filter_var($value, FILTER_VALIDATE_INT);

		if ($normalized === false) {
			throw new HTTPException(422, 'VALIDATION_INTEGER_REQUIRED', sprintf('Parameter "%s" must be an integer.', $name), [
				'parameter' => $name,
			]);
		}

		$minimum = $rule['min'] ?? null;
		$maximum = $rule['max'] ?? null;

		if (is_int($minimum) && $normalized < $minimum) {
			throw new HTTPException(422, 'VALIDATION_INTEGER_MIN', sprintf('Parameter "%s" must be at least %d.', $name, $minimum), [
				'parameter' => $name,
				'min' => $minimum,
			]);
		}

		if (is_int($maximum) && $normalized > $maximum) {
			throw new HTTPException(422, 'VALIDATION_INTEGER_MAX', sprintf('Parameter "%s" must be at most %d.', $name, $maximum), [
				'parameter' => $name,
				'max' => $maximum,
			]);
		}

		return $normalized;
	}

	private function normalizeFloat(string $name, mixed $value, array $rule): float
	{
		$normalized = filter_var($value, FILTER_VALIDATE_FLOAT);

		if ($normalized === false) {
			throw new HTTPException(422, 'VALIDATION_FLOAT_REQUIRED', sprintf('Parameter "%s" must be a number.', $name), [
				'parameter' => $name,
			]);
		}

		$minimum = $rule['min'] ?? null;
		$maximum = $rule['max'] ?? null;

		if (is_numeric($minimum) && $normalized < (float) $minimum) {
			throw new HTTPException(422, 'VALIDATION_FLOAT_MIN', sprintf('Parameter "%s" must be at least %s.', $name, (string) $minimum), [
				'parameter' => $name,
				'min' => (float) $minimum,
			]);
		}

		if (is_numeric($maximum) && $normalized > (float) $maximum) {
			throw new HTTPException(422, 'VALIDATION_FLOAT_MAX', sprintf('Parameter "%s" must be at most %s.', $name, (string) $maximum), [
				'parameter' => $name,
				'max' => (float) $maximum,
			]);
		}

		return (float) $normalized;
	}

	private function normalizeBool(string $name, mixed $value): bool
	{
		$normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		if ($normalized === null) {
			throw new HTTPException(422, 'VALIDATION_BOOLEAN_REQUIRED', sprintf('Parameter "%s" must be a boolean.', $name), [
				'parameter' => $name,
			]);
		}

		return $normalized;
	}

	private function normalizeString(string $name, mixed $value, array $rule): string
	{
		if (!is_scalar($value)) {
			throw new HTTPException(422, 'VALIDATION_STRING_REQUIRED', sprintf('Parameter "%s" must be a string.', $name), [
				'parameter' => $name,
			]);
		}

		$normalized = (string) $value;

		if (($rule['trim'] ?? true) === true) {
			$normalized = trim($normalized);
		}

		$minimumLength = $rule['min_length'] ?? null;
		$maximumLength = $rule['max_length'] ?? null;

		if (is_int($minimumLength) && strlen($normalized) < $minimumLength) {
			throw new HTTPException(422, 'VALIDATION_STRING_MIN_LENGTH', sprintf('Parameter "%s" is too short.', $name), [
				'parameter' => $name,
				'min_length' => $minimumLength,
			]);
		}

		if (is_int($maximumLength) && strlen($normalized) > $maximumLength) {
			throw new HTTPException(422, 'VALIDATION_STRING_MAX_LENGTH', sprintf('Parameter "%s" is too long.', $name), [
				'parameter' => $name,
				'max_length' => $maximumLength,
			]);
		}

		return $normalized;
	}

	private function pdoTypeFor(mixed $value): int
	{
		if (is_int($value)) {
			return PDO::PARAM_INT;
		}

		if (is_bool($value)) {
			return PDO::PARAM_BOOL;
		}

		if ($value === null) {
			return PDO::PARAM_NULL;
		}

		return PDO::PARAM_STR;
	}

	private function formatResult(PDO $connection, PDOStatement $statement, string $mode): mixed
	{
		return match ($mode) {
			'one' => $statement->fetch() ?: null,
			'value' => $statement->fetchColumn(),
			'write' => [
				'affected_rows' => $statement->rowCount(),
				'last_insert_id' => $connection->lastInsertId(),
			],
			default => $statement->fetchAll(),
		};
	}
}
