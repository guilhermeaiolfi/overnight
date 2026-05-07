<?php

declare(strict_types=1);

namespace ON\Middleware;

use Psr\Http\Message\ServerRequestInterface;

final class ValidationResult
{
	private function __construct(
		private bool $valid,
		private ServerRequestInterface $request
	) {
	}

	public static function valid(ServerRequestInterface $request): self
	{
		return new self(true, $request);
	}

	public static function fail(ServerRequestInterface $request): self
	{
		return new self(false, $request);
	}

	public function isValid(): bool
	{
		return $this->valid;
	}

	public function request(): ServerRequestInterface
	{
		return $this->request;
	}
}
