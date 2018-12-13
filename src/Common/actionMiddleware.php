<?php
namespace ON\Common;

use ON\Router\ActionMiddlewareDecorator;
use Psr\Http\Message\ResponseInterface;

/**
 * Convenience wrapper around instantiation of a CallableMiddlewareDecorator instance.
 *
 * Usage:
 *
 * <code>
 * $pipeline->pipe(middleware(function ($req, $handler) {
 *     // do some work
 * }));
 * </code>
 *
 * @return Middleware\CallableMiddlewareDecorator
 */
function actionMiddleware(callable $middleware)
{
    return new ActionMiddlewareDecorator($middleware);
}
