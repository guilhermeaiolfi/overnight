<?php

declare(strict_types=1);

namespace ON\Swoole;

use ON\RequestStack;
use ON\RequestStackInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swoole\Coroutine;

class SwooleRequestStack implements RequestStackInterface
{
	/** @var array<int, RequestStack> */
	private array $stacks = [];

	public function beginRequest(ServerRequestInterface $request, callable $callback): mixed
	{
		return $this->getStack()->beginRequest($request, $callback);
	}

	public function usingRequest(ServerRequestInterface $request, callable $callback): mixed
	{
		return $this->getStack()->usingRequest($request, $callback);
	}

	public function isMainRequest(ServerRequestInterface $request): bool
	{
		return $this->getStack()->isMainRequest($request);
	}

	public function isCurrentMainRequest(): bool
	{
		return $this->getStack()->isCurrentMainRequest();
	}

	public function push(ServerRequestInterface $request): void
	{
		$this->getStack()->push($request);
	}

	public function pop(): ?ServerRequestInterface
	{
		return $this->getStack()->pop();
	}

	public function getCurrentRequest(): ?ServerRequestInterface
	{
		return $this->getStack()->getCurrentRequest();
	}

	public function getMainRequest(): ?ServerRequestInterface
	{
		return $this->getStack()->getMainRequest();
	}

	public function getParentRequest(): ?ServerRequestInterface
	{
		return $this->getStack()->getParentRequest();
	}

	private function getStack(): RequestStack
	{
		if (! class_exists(Coroutine::class)) {
			throw new RuntimeException('SwooleRequestStack requires the Swoole extension.');
		}

		$coroutineId = Coroutine::getCid();
		if ($coroutineId < 0) {
			$coroutineId = 0;
		}

		return $this->stacks[$coroutineId] ??= new RequestStack();
	}
}
