<?php

declare(strict_types=1);

spl_autoload_register(static function (string $className): void {
	$prefix = 'Brenner\\';

	if (strncmp($className, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$relativeClass = substr($className, strlen($prefix));
	$path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

	if (is_file($path)) {
		require_once $path;
	}
});
