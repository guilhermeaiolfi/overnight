<?php

declare(strict_types=1);

namespace ON\Middleware;

use ON\Application;
use ON\Event\NamedEvent;
use ON\RequestStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * This class keeps track of all request made in the process.
 * Because it's not just the original request we need to keep.
 * If we are dealing with views and there are subrequests,
 * we should be able to get all of those if necessary.
 */
class RegisterRequestMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected Application $app,
		protected RequestStack $stack
	) {
	}

	public function process(ServerRequestInterface $request,  RequestHandlerInterface $handler): ResponseInterface
	{
		$this->stack->push($request);
		if ($this->app->hasExtension('events')) {
			$this->app->ext('events')->dispatch(new NamedEvent('core.request', $request));
		}
		try {
			return $handler->handle($request);
		} finally {
			$this->stack->pop();
		}
	}
}
