<?php

declare(strict_types=1);

use Brenner\Api\ActionRunner;
use Brenner\Database\DatabaseManager;
use Brenner\Support\Config;
use Brenner\Support\HttpException;

require_once dirname(__DIR__) . '/src/autoload.php';

$config = Config::fromDirectory(dirname(__DIR__) . '/config');
$timezone = (string) $config->app('timezone', 'UTC');
date_default_timezone_set($timezone);

applyCors($config);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
	$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

	if (!in_array($method, ['GET', 'POST'], true)) {
		throw new HttpException(405, 'Only GET and POST are supported.');
	}

	$body = readJsonBody();
	$actionName = resolveActionName($body);

	if ($actionName === '') {
		throw new HttpException(422, 'Missing action name.');
	}

	authenticate($config, $body);

	$input = $method === 'GET'
		? array_diff_key($_GET, ['action' => true, 'api_key' => true])
		: resolveInputParams($body);

	$runner = new ActionRunner($config, new DatabaseManager($config->databases()));
	$result = $runner->run($actionName, $method, $input);

	echo json_encode(array_merge([
		'ok' => true,
		'timestamp' => date(DATE_ATOM),
	], $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (HttpException $exception) {
	sendError(
		$exception->statusCode(),
		$exception->getMessage(),
		$exception->details()
	);
} catch (Throwable $exception) {
	$debug = (bool) $config->app('debug', false);

	sendError(
		500,
		$debug ? $exception->getMessage() : 'Internal server error.',
		$debug ? ['trace' => explode(PHP_EOL, $exception->getTraceAsString())] : []
	);
}

function applyCors(Config $config): void
{
	$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
	$allowedOrigins = $config->app('allowed_origins', []);

	header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

	if (!is_string($origin) || !is_array($allowedOrigins) || $allowedOrigins === []) {
		return;
	}

	if (in_array('*', $allowedOrigins, true)) {
		header('Access-Control-Allow-Origin: *');
		return;
	}

	if (in_array($origin, $allowedOrigins, true)) {
		header('Access-Control-Allow-Origin: ' . $origin);
		header('Vary: Origin');
	}
}

function readJsonBody(): array
{
	$rawBody = file_get_contents('php://input');

	if (!is_string($rawBody) || trim($rawBody) === '') {
		return [];
	}

	$data = json_decode($rawBody, true);

	if (!is_array($data)) {
		throw new HttpException(400, 'Request body must contain valid JSON.');
	}

	return $data;
}

function resolveActionName(array $body): string
{
	$action = $_GET['action'] ?? $body['action'] ?? '';

	return is_string($action) ? trim($action) : '';
}

function resolveInputParams(array $body): array
{
	$params = $body['params'] ?? $body;

	if (!is_array($params)) {
		throw new HttpException(400, 'Request params must be an object.');
	}

	unset($params['action'], $params['api_key']);

	return $params;
}

function authenticate(Config $config, array $body): void
{
	$expectedApiKey = $config->app('api_key');

	if ($expectedApiKey === null || $expectedApiKey === '') {
		return;
	}

	$providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $body['api_key'] ?? null;

	if (!is_string($providedApiKey) || !hash_equals((string) $expectedApiKey, $providedApiKey)) {
		throw new HttpException(401, 'Invalid API key.');
	}
}

function sendError(int $statusCode, string $message, array $details = []): void
{
	http_response_code($statusCode);

	echo json_encode([
		'ok' => false,
		'error' => [
			'status' => $statusCode,
			'message' => $message,
			'details' => $details,
		],
		'timestamp' => date(DATE_ATOM),
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
