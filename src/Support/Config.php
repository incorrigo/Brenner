<?php

declare(strict_types=1);

namespace Brenner\Support;

use RuntimeException;

final class Config
{
	private array $app;
	private array $databases;
	private array $actions;

	public function __construct(array $app, array $databases, array $actions)
	{
		$this->app = $app;
		$this->databases = $databases;
		$this->actions = $actions;
	}

	public static function fromDirectory(string $configDirectory): self
	{
		$app = self::loadConfigPair($configDirectory, 'app');
		$databases = self::loadConfigPair($configDirectory, 'databases');
		$actions = self::loadConfigPair($configDirectory, 'actions');

		return new self($app, $databases, $actions);
	}

	public function app(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->app;
		}

		return $this->app[$key] ?? $default;
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
}
