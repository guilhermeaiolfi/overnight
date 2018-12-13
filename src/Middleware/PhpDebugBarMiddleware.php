<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use PhpMiddleware\PhpDebugBar\PhpDebugBarMiddleware as DebugBarMiddleware;

/**
 * PhpDebugBarMiddleware
 *
 */
class PhpDebugBarMiddleware extends DebugBarMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler)
    {
        if ($request->getAttribute("PARENT-REQUEST")) {
            return $handler->process($request);
        }
        return parent::process($request, $handler);
    }
}