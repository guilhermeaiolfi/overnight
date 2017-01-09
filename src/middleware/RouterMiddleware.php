<?php

namespace ON\middleware;

class RouterMiddleware
{
    private $router;

    public function __construct(\ON\Router $router)
    {
        $this->router = $router;
    }

    public function __invoke($request, $response, callable $next)
    {
        if ($request->getAttribute('ON:route')) {
            // routing is already done
            return $response;
        }
        $route = $this->router->matchRequest($request);
        if ($route) {
            $request = $request->withAttribute("ON:route", $route);
        } else {
            $message = sprintf('No route found for "%s %s"', $request->getMethod(), $request->getUri()->getPath());
            /*if ($referer = $request->getServerParams()['HTTP_REFERER']) {
                $message .= sprintf(' (from "%s")', $referer);
            }*/
            throw new \Exception($message);
        }
        return $next($request, $response);
    }
}
?>