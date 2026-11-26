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
	private static int $nextInstanceId = 0;

	private string $contextKey;

	private ?RequestStack $fallbackStack = null;

	/** @var array<int, RequestStack> */
	private array $stacks = [];

	public function __construct()
	{
		$this->contextKey = self::class . ':' . (++self::$nextInstanceId);
	}

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
		if ($coroutineId <= 0) {
			return $this->fallbackStack ??= new RequestStack();
		}

		if (method_exists(Coroutine::class, 'getContext')) {
			$context = Coroutine::getContext();
			if ($context !== null) {
				if (! isset($context[$this->contextKey]) || ! $context[$this->contextKey] instanceof RequestStack) {
					$context[$this->contextKey] = new RequestStack();
				}

				return $context[$this->contextKey];
			}
		}

		if (! isset($this->stacks[$coroutineId])) {
			$this->stacks[$coroutineId] = new RequestStack();
			if (method_exists(Coroutine::class, 'defer')) {
				Coroutine::defer(function () use ($coroutineId): void {
					unset($this->stacks[$coroutineId]);
				});
			}
		}

		return $this->stacks[$coroutineId];
	}
}
