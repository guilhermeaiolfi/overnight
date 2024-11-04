<?php

declare(strict_types=1);

namespace ON\Middleware;

use ArrayIterator;
use function iterator_to_array;
use Laminas\Stratigility\EmptyPipelineHandler;
use Laminas\Stratigility\IterableMiddlewarePipeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SplPriorityQueue;
use Traversable;

/**
 * Pipe middleware like unix pipes.
 *
 * This class implements a pipeline of middleware, which can be attached using
 * the `pipe()` method, and is itself middleware.
 *
 * It creates an instance of `Next` internally, invoking it with the provided
 * request and response instances, passing the original request and the returned
 * response to the `$next` argument when complete.
 *
 * Inspired by Sencha Connect.
 *
 * @see https://github.com/senchalabs/connect
 */
final class MiddlewarePriorityPipe implements IterableMiddlewarePipeInterface
{
	/** @var SplPriorityQueue<MiddlewareInterface> */
	private SplPriorityQueue $pipeline;

	private int $serial = PHP_INT_MAX;

	/**
	 * Initializes the queue.
	 */
	public function __construct()
	{
		/** @psalm-var SplPriorityQueue<MiddlewareInterface> */
		$this->pipeline = new SplPriorityQueue();
	}

	/**
	 * Perform a deep clone.
	 */
	public function __clone()
	{
		$this->pipeline = clone $this->pipeline;
	}

	/**
	 * Handle an incoming request.
	 *
	 * Attempts to handle an incoming request by doing the following:
	 *
	 * - Cloning itself, to produce a request handler.
	 * - Dequeuing the first middleware in the cloned handler.
	 * - Processing the first middleware using the request and the cloned handler.
	 *
	 * If the pipeline is empty at the time this method is invoked, it will
	 * raise an exception.
	 *
	 * @throws Exception\EmptyPipelineException If no middleware is present in
	 *     the instance in order to process the request.
	 */
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return $this->process($request, new EmptyPipelineHandler(self::class));
	}

	/**
	 * PSR-15 middleware invocation.
	 *
	 * Executes the internal pipeline, passing $handler as the "final
	 * handler" in cases when the pipeline exhausts itself.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return (new NextPriority($this->pipeline, $handler))->handle($request);
	}

	/**
	 * Attach middleware to the pipeline.
	 *
	 * `priority` is used to shape the order in which middleware is piped to the
	 * application. Values are integers, with high values having higher priority
	 * (piped earlier), and low/negative values having lower priority (piped last).
	 * Default priority if none is specified is 1. Middleware with the same
	 * priority are piped in the order in which they appear.
	 */
	public function pipe(MiddlewareInterface $middleware, int $priority = 1): void
	{
		$this->pipeline->insert($middleware, [$priority, $this->serial]);
		$this->serial -= 1;
	}

	/** @return Traversable<int, MiddlewareInterface> */
	public function getIterator(): Traversable
	{
		return new ArrayIterator(
			iterator_to_array(
				clone $this->pipeline,
				false,
			),
		);
	}
}
