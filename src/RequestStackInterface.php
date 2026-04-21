<?php

declare(strict_types=1);

namespace ON;

use Psr\Http\Message\ServerRequestInterface;

interface RequestStackInterface
{
	public function beginRequest(ServerRequestInterface $request, callable $callback): mixed;

	public function usingRequest(ServerRequestInterface $request, callable $callback): mixed;

	public function isMainRequest(ServerRequestInterface $request): bool;

	public function isCurrentMainRequest(): bool;

	public function push(ServerRequestInterface $request): void;

	public function pop(): ?ServerRequestInterface;

	public function getCurrentRequest(): ?ServerRequestInterface;

	public function getMainRequest(): ?ServerRequestInterface;

	public function getParentRequest(): ?ServerRequestInterface;
}
