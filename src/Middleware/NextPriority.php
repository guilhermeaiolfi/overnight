<?php

declare(strict_types=1);

namespace ON\Middleware;

use Laminas\Stratigility\Exception\MiddlewarePipeNextHandlerAlreadyCalledException;
use ON\RequestStackInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SplPriorityQueue;

/**
 * Iterate a queue of middlewares and execute them.
 */
final class NextPriority implements RequestHandlerInterface
{
	private RequestHandlerInterface $fallbackHandler;

	/** @var SplPriorityQueue<MiddlewareInterface> */
	private ?SplPriorityQueue $queue;

	/**
	 * Clones the queue provided to allow re-use.
	 *
	 * @param SplPriorityQueue<MiddlewareInterface> $queue
	 * @param RequestHandlerInterface $fallbackHandler Fallback handler to
	 *     invoke when the queue is exhausted.
	 */
	public function __construct(
		SplPriorityQueue $queue,
		RequestHandlerInterface $fallbackHandler,
		private RequestStackInterface $requestStack
	)
	{
		$this->queue = clone $queue;
		$this->fallbackHandler = $fallbackHandler;
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return $this->requestStack->usingRequest(
			$request,
			function (ServerRequestInterface $request): ResponseInterface {
				return $this->handleWithCurrentRequest($request);
			}
		);
	}

	private function handleWithCurrentRequest(ServerRequestInterface $request): ResponseInterface
	{
		if ($this->queue === null) {
			throw MiddlewarePipeNextHandlerAlreadyCalledException::create();
		}

		if ($this->queue->isEmpty()) {
			$this->queue = null;

			return $this->fallbackHandler->handle($request);
		}


		$middleware = $this->queue->extract();

		//dd($middleware);
		$next = clone $this; // deep clone is not used intentionally
		$this->queue = null; // mark queue as processed at this nesting level

		return $middleware->process($request, $next);
	}
}
