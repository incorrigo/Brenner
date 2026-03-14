<?php

declare(strict_types=1);

namespace Brenner\Support;

use RuntimeException;

final class HTTPException extends RuntimeException
{
	private int $statusCode;
	private string $errorCode;
	private array $details;

	public function __construct(int $statusCode, string $errorCode, string $message, array $details = [])
	{
		parent::__construct($message);

		$this->statusCode = $statusCode;
		$this->errorCode = $errorCode;
		$this->details = $details;
	}

	public function statusCode(): int
	{
		return $this->statusCode;
	}

	public function errorCode(): string
	{
		return $this->errorCode;
	}

	public function details(): array
	{
		return $this->details;
	}
}
