<?php

namespace ON\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Psr\Http\Message\ServerRequestInterface;
use PhpMiddleware\PhpDebugBar\PhpDebugBarMiddleware as DebugBarMiddleware;

/**
 * PhpDebugBarMiddleware
 *
 */
class PhpDebugBarMiddleware extends DebugBarMiddleware
{
    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        if ($request->getAttribute("PARENT-REQUEST")) {
            return $delegate->process($request);
        }
        return parent::process($request, $delegate);
    }
}
