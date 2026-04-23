<?php

declare(strict_types=1);

namespace ON;

use function array_key_last;
use function count;
use ON\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

class RequestStack implements RequestStackInterface
{
	/**
	 * @var RequestStackFrame[]
	 */
	private array $frames = [];

	/**
	 * @param ServerRequestInterface[] $requests
	 */
	public function __construct(array $requests = [])
	{
		foreach ($requests as $request) {
			$this->push($request);
		}
	}

	public function beginRequest(ServerRequestInterface $request, callable $callback): mixed
	{
		$request = $this->normalizeRequest($request);
		$this->push($request);

		try {
			return $callback($request);
		} finally {
			$this->pop();
		}
	}

	public function usingRequest(ServerRequestInterface $request, callable $callback): mixed
	{
		$request = $this->normalizeRequest($request);
		$frame = $this->getCurrentFrame();
		if (! $frame) {
			return $this->beginRequest($request, $callback);
		}

		$previousRequest = $frame->currentRequest;
		$frame->currentRequest = $request;

		try {
			return $callback($request);
		} finally {
			$frame->currentRequest = $previousRequest;
		}
	}

	public function isMainRequest(ServerRequestInterface $request): bool
	{
		return $this->getMainRequest() === $request;
	}

	public function isCurrentMainRequest(): bool
	{
		return count($this->frames) === 1;
	}

	public function push(ServerRequestInterface $request): void
	{
		$request = $this->normalizeRequest($request);
		$this->frames[] = new RequestStackFrame(
			$request,
			$this->getCurrentRequest()
		);
	}

	public function pop(): ?ServerRequestInterface
	{
		if (! $this->frames) {
			return null;
		}

		return array_pop($this->frames)->currentRequest;
	}

	public function getCurrentRequest(): ?ServerRequestInterface
	{
		return $this->getCurrentFrame()?->currentRequest;
	}

	public function getMainRequest(): ?ServerRequestInterface
	{
		return $this->frames[0]->currentRequest ?? null;
	}

	public function getParentRequest(): ?ServerRequestInterface
	{
		return $this->getCurrentFrame()?->parentRequest;
	}

	private function getCurrentFrame(): ?RequestStackFrame
	{
		if (! $this->frames) {
			return null;
		}

		return $this->frames[array_key_last($this->frames)];
	}

	private function normalizeRequest(ServerRequestInterface $request): ServerRequestInterface
	{
		if ($request->getAttribute(RequestContext::class) instanceof RequestContext) {
			return $request;
		}

		$parentRequest = $this->getCurrentRequest();

		return $request->withAttribute(
			RequestContext::class,
			new RequestContext(
				$parentRequest,
				$parentRequest?->getAttribute(RequestContext::class)
			)
		);
	}
}

final class RequestStackFrame
{
	public function __construct(
		public ServerRequestInterface $currentRequest,
		public readonly ?ServerRequestInterface $parentRequest = null
	) {
	}
}
