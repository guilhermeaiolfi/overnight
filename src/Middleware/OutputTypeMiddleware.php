<?php
namespace ON\Middleware;

use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;

class OutputTypeMiddleware implements ServerMiddlewareInterface
{
    public function process (ServerRequestInterface $request, DelegateInterface $delegate)
    {
      $accept = $request->getHeader('Accept')[0];
      if (!$accept || ! preg_match('#^application/([^+\s]+\+)?json#', $accept)) {
          $request = $request->withAttribute("OUTPUT_TYPE", "html");
      } else {
        $request = $request->withAttribute("OUTPUT_TYPE", "json");
      }
      return $delegate->process($request);
    }
}