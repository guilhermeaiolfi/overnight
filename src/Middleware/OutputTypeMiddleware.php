<?php
namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;

class OutputTypeMiddleware implements MiddlewareInterface
{
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
      $accept = $request->getHeader('Accept')[0];
      if (!$accept || ! preg_match('#^application/([^+\s]+\+)?json#', $accept)) {
          $request = $request->withAttribute("OUTPUT_TYPE", "html");
      } else {
        $request = $request->withAttribute("OUTPUT_TYPE", "json");
      }
      return $handler->process($request);
    }
}