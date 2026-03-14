<?php

declare(strict_types=1);

namespace Brenner\Api;

use Brenner\Auth\ClientCredentialStore;
use Brenner\Auth\RotatingGUIDSessionStore;
use Brenner\Database\DatabaseManager;
use Brenner\Support\Config;
use Brenner\Support\HTTPException;
use PDO;
use Throwable;

final class APIActionRunner
{
	private Config $config;
	private DatabaseManager $databaseManager;
	private ClientCredentialStore $clientCredentialStore;
	private RotatingGUIDSessionStore $sessionStore;

	public function __construct(
		Config $config,
		DatabaseManager $databaseManager,
		ClientCredentialStore $clientCredentialStore,
		RotatingGUIDSessionStore $sessionStore
	) {
		$this->config = $config;
		$this->databaseManager = $databaseManager;
		$this->clientCredentialStore = $clientCredentialStore;
		$this->sessionStore = $sessionStore;
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
		$sessionData = $this->sessionStore->openSession($client['client_id'], $client['scopes']);

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
			'session' => $sessionData,
			'meta' => [
				'method' => $requestMethod,
				'type' => 'session_open',
			],
		];
	}

	private function runSQLAction(string $actionName, string $requestMethod, array $action, array $params): array
	{
		$databaseName = (string) ($action['database'] ?? '');

		if ($databaseName === '') {
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

	private function coerceValue(string $name, mixed $value, array $rule): mixed
	{
		$type = strtolower((string) ($rule['type'] ?? 'string'));

		return match ($type) {
			'int', 'integer' => $this->normalizeInt($name, $value, $rule),
			'float' => $this->normalizeFloat($name, $value, $rule),
			'bool', 'boolean' => $this->normalizeBool($name, $value),
			'string' => $this->normalizeString($name, $value, $rule),
			default => throw new HTTPException(500, 'PARAMETER_TYPE_UNSUPPORTED', sprintf('Unsupported parameter type "%s".', $type), [
				'parameter' => $name,
				'type' => $type,
			]),
		};
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

	private function formatResult(PDO $connection, \PDOStatement $statement, string $mode): mixed
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
