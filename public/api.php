<?php

declare(strict_types=1);

use Brenner\API\APIActionRunner;
use Brenner\Auth\ClientCredentialStore;
use Brenner\Auth\RotatingGUIDSessionStore;
use Brenner\Database\DatabaseManager;
use Brenner\Support\Config;
use Brenner\Support\HTTPException;

require_once dirname(__DIR__) . '/src/autoload.php';

$config = Config::fromDirectory(dirname(__DIR__) . '/config');
$timezone = (string) $config->app('timezone', 'UTC');
date_default_timezone_set($timezone);

header('Content-Type: application/json; charset=utf-8');
$requestID = resolveRequestID();
header('X-Request-ID: ' . $requestID);
$actionName = null;

try {
	ensureHTTPS($config);
	ensurePOSTRequest();

	$body = readJsonBody();
	$actionName = resolveActionName($body);

	if ($actionName === '') {
		throw new HTTPException(422, 'ACTION_MISSING', 'Missing action name.');
	}

	$params = resolveInputParams($body);
	$action = resolveActionConfig($config, $actionName);
	$guidStore = new RotatingGUIDSessionStore($config->auth());
	$runner = new APIActionRunner(
		$config,
		new DatabaseManager($config->databases()),
		new ClientCredentialStore($config->auth()),
		$guidStore
	);

	if (($action['requires_auth'] ?? true) === true) {
		$guid = resolveGUID($body);
		$consumeGUID = actionConsumesGUID($action);
		$requestFingerprint = $consumeGUID ? buildRequestFingerprint($actionName, $params) : null;

		$result = $guidStore->execute(
			$guid,
			$consumeGUID,
			$requestFingerprint,
			function (array $clientContext, array $guidState) use ($action, $actionName, $config, $params, $requestID, $runner): array {
				try {
					authorizeScopes($action, $clientContext);
					$actionResult = $runner->run($actionName, 'POST', $params);

					return [
						'status_code' => 200,
						'response' => buildSuccessResponse(
							$requestID,
							$actionResult,
							buildClientMeta($actionResult['client'] ?? null, $clientContext),
							$guidState
						),
					];
				} catch (HTTPException $exception) {
					return [
						'status_code' => $exception->statusCode(),
						'response' => buildErrorResponse(
							$requestID,
							$actionName,
							$exception->statusCode(),
							$exception->errorCode(),
							$exception->getMessage(),
							$exception->details(),
							buildClientMeta(null, $clientContext),
							$guidState
						),
					];
				} catch (Throwable $exception) {
					$debug = (bool) $config->app('debug', false);

					return [
						'status_code' => 500,
						'response' => buildErrorResponse(
							$requestID,
							$actionName,
							500,
							'INTERNAL_SERVER_ERROR',
							$debug ? $exception->getMessage() : 'Internal server error.',
							$debug ? ['trace' => explode(PHP_EOL, $exception->getTraceAsString())] : [],
							buildClientMeta(null, $clientContext),
							$guidState
						),
					];
				}
			}
		);

		emitResponse((int) $result['status_code'], $result['response']);
		exit;
	}

	$actionResult = $runner->run($actionName, 'POST', $params);
	emitResponse(
		200,
		buildSuccessResponse(
			$requestID,
			$actionResult,
			$actionResult['client'] ?? null,
			$actionResult['guid_state'] ?? null
		)
	);
} catch (HTTPException $exception) {
	emitResponse(
		$exception->statusCode(),
		buildErrorResponse(
			$requestID,
			$actionName,
			$exception->statusCode(),
			$exception->errorCode(),
			$exception->getMessage(),
			$exception->details()
		)
	);
} catch (Throwable $exception) {
	$debug = (bool) $config->app('debug', false);

	emitResponse(
		500,
		buildErrorResponse(
			$requestID,
			$actionName,
			500,
			'INTERNAL_SERVER_ERROR',
			$debug ? $exception->getMessage() : 'Internal server error.',
			$debug ? ['trace' => explode(PHP_EOL, $exception->getTraceAsString())] : []
		)
	);
}

function ensureHTTPS(Config $config): void
{
	if ((bool) $config->app('require_https', true) !== true || PHP_SAPI === 'cli') {
		return;
	}

	$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
	$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
	$isTrustedForwardedProto = (bool) $config->app('trust_forwarded_proto', true) && $forwardedProto === 'https';
	$isHTTPS = $https !== '' && $https !== 'off';

	if ($isHTTPS || $isTrustedForwardedProto || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
		return;
	}

	throw new HTTPException(403, 'HTTPS_REQUIRED', 'HTTPS is required for this API endpoint.');
}

function ensurePOSTRequest(): void
{
	$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

	if ($method !== 'POST') {
		throw new HTTPException(405, 'METHOD_NOT_ALLOWED', 'This API accepts POST requests only.');
	}
}

function readJsonBody(): array
{
	$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

	if (PHP_SAPI !== 'cli' && !str_contains($contentType, 'application/json')) {
		throw new HTTPException(415, 'CONTENT_TYPE_INVALID', 'Content-Type must be application/json.');
	}

	$rawBody = file_get_contents('php://input');

	if (!is_string($rawBody) || trim($rawBody) === '') {
		throw new HTTPException(400, 'REQUEST_BODY_EMPTY', 'Request body must contain JSON.');
	}

	$data = json_decode($rawBody, true);

	if (!is_array($data)) {
		throw new HTTPException(400, 'REQUEST_JSON_INVALID', 'Request body must contain valid JSON.');
	}

	return $data;
}

function resolveActionName(array $body): string
{
	$action = $body['action'] ?? '';

	return is_string($action) ? trim($action) : '';
}

function resolveInputParams(array $body): array
{
	$params = $body['params'] ?? [];

	if (!is_array($params)) {
		throw new HTTPException(400, 'REQUEST_PARAMS_INVALID', 'Request params must be an object.');
	}

	return $params;
}

function resolveGUID(array $body): string
{
	$guid = trim((string) ($body['guid'] ?? ''));

	if ($guid === '') {
		throw new HTTPException(401, 'AUTH_GUID_REQUIRED', 'Authenticated actions require a GUID.');
	}

	return $guid;
}

function resolveActionConfig(Config $config, string $actionName): array
{
	try {
		return $config->action($actionName);
	} catch (Throwable $exception) {
		throw new HTTPException(404, 'ACTION_NOT_FOUND', 'Unknown action.', ['action' => $actionName]);
	}
}

function authorizeScopes(array $action, array $clientContext): void
{
	$requiredScopes = array_values(array_filter($action['scopes'] ?? [], 'is_string'));

	if ($requiredScopes === []) {
		return;
	}

	$clientScopes = array_values(array_filter($clientContext['scopes'] ?? [], 'is_string'));
	$missingScopes = array_values(array_diff($requiredScopes, $clientScopes));

	if ($missingScopes !== []) {
		throw new HTTPException(403, 'AUTH_SCOPE_DENIED', 'GUID does not grant the required scopes.', [
			'missing_scopes' => $missingScopes,
		]);
	}
}

function buildClientMeta(?array $explicitClient, ?array $clientContext = null): ?array
{
	if (is_array($explicitClient)) {
		return $explicitClient;
	}

	if (!is_array($clientContext)) {
		return null;
	}

	return [
		'client_id' => (string) ($clientContext['client_id'] ?? ''),
		'scopes' => array_values(array_filter($clientContext['scopes'] ?? [], 'is_string')),
	];
}

function buildSuccessResponse(string $requestID, array $actionResult, ?array $client = null, ?array $guidState = null): array
{
	return [
		'ok' => true,
		'request_id' => $requestID,
		'timestamp' => date(DATE_ATOM),
		'action' => $actionResult['action'] ?? null,
		'data' => $actionResult['data'] ?? null,
		'meta' => $actionResult['meta'] ?? null,
		'client' => $client ?? ($actionResult['client'] ?? null),
		'guid' => $guidState['guid'] ?? null,
		'guid_sequence' => $guidState['guid_sequence'] ?? null,
		'guid_expires_at' => $guidState['guid_expires_at'] ?? null,
		'error' => null,
	];
}

function buildErrorResponse(
	string $requestID,
	?string $actionName,
	int $statusCode,
	string $errorCode,
	string $message,
	array $details = [],
	?array $client = null,
	?array $guidState = null
): array {
	return [
		'ok' => false,
		'request_id' => $requestID,
		'timestamp' => date(DATE_ATOM),
		'action' => $actionName,
		'data' => null,
		'meta' => null,
		'client' => $client,
		'guid' => $guidState['guid'] ?? null,
		'guid_sequence' => $guidState['guid_sequence'] ?? null,
		'guid_expires_at' => $guidState['guid_expires_at'] ?? null,
		'error' => [
			'http_status' => $statusCode,
			'code' => $errorCode,
			'message' => $message,
			'details' => $details,
		],
	];
}

function emitResponse(int $statusCode, array $response): void
{
	http_response_code($statusCode);
	echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function resolveRequestID(): string
{
	$requestID = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;

	if (is_string($requestID) && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestID) === 1) {
		return $requestID;
	}

	return bin2hex(random_bytes(16));
}

function actionConsumesGUID(array $action): bool
{
	if (array_key_exists('consume_guid', $action)) {
		return (bool) $action['consume_guid'];
	}

	return strtolower((string) ($action['type'] ?? '')) === 'sql'
		&& strtolower((string) ($action['result'] ?? '')) === 'write';
}

function buildRequestFingerprint(string $actionName, array $params): string
{
	$normalized = normalizeForFingerprint([
		'action' => $actionName,
		'params' => $params,
	]);

	return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function normalizeForFingerprint(mixed $value): mixed
{
	if (!is_array($value)) {
		return $value;
	}

	if (array_is_list($value)) {
		return array_map(static fn (mixed $item): mixed => normalizeForFingerprint($item), $value);
	}

	ksort($value);

	foreach ($value as $key => $item) {
		$value[$key] = normalizeForFingerprint($item);
	}

	return $value;
}
