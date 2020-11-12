<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Mezzio\Router\Route;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\EmptyResponse;
use ON\Auth\AuthenticationServiceInterface;
use ON\Exception\SecurityException;
use ON\User\UserInterface;
use ON\Router\StatefulRouterInterface;
use ON\Application;
use ON\Container\MiddlewareFactory;

class SecurityMiddleware implements MiddlewareInterface
{

    /**
     * @var ContainerInterface|null
     */
    protected $container;

    protected $auth;
    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @param RouterInterface $router
     * @param ResponseInterface $responsePrototype
     * @param ContainerInterface|null $container
     */
    public function __construct(
        AuthenticationServiceInterface $auth,
        ContainerInterface $container = null,
        StatefulRouterInterface $router
    ) {
        $this->auth = $auth;
        $this->container = $container;
        $this->router = $router;
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (!$routeResult) {
            return $handler->handle($request);
        }
        $middleware = $routeResult->getMatchedRoute()->getMiddleware();
        if (!is_string($middleware)) {
            $middleware = $middleware->getString();
        }
        if (!class_exists($middleware)) {
            list($middleware, $action) = explode("::", $middleware);
        }

        $page = $this->container->get($middleware);

        if ($page->isSecure() && !$this->auth->hasIdentity()) {
            $config = $this->container->get('config');
            //throw new Exception('User has no permittion!');
            return $this->processForward($config->get('login'), $request);
            //return new RedirectResponse($this->router->gen("login"));
        }
        try {
            return $handler->handle($request);
        } catch (SecurityException $e) {
            return new EmptyResponse(403);
        }
    }

    public function processForward($middleware, $request) {
        $result = $request->getAttribute(RouteResult::class);
        $matched = $result->getMatchedRoute();
        if (is_string($middleware)) {
            $middleware = $this->container->get(MiddlewareFactory::class)->prepare($middleware);
        }
        $result = RouteResult::fromRoute(new Route($matched->getPath(), $middleware, $matched->getAllowedMethods(), $matched->getName()));
        $request = $request->withAttribute(RouteResult::class, $result);
        return $this->container->get(Application::class)->runAction($request);
    }
}