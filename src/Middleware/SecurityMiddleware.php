<?php

namespace ON\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface as ServerMiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\RouterInterface;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Diactoros\Response\EmptyResponse;
use Zend\Expressive\Helper\UrlHelper;
use Zend\Authentication\AuthenticationServiceInterface;
use ON\Exception\SecurityException;
use ON\User\UserInterface;
use ON\Router\StatefulRouterInterface;

class SecurityMiddleware implements ServerMiddlewareInterface
{

    /**
     * @var ContainerInterface|null
     */
    protected $container;

    protected $urlHelper;

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
     * @param DelegateInterface $delegate
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $routeResult = $request->getAttribute(RouteResult::class, false);

        if (!$routeResult) {
            return $delegate->process($request);
        }
        $middleware = $routeResult->getMatchedMiddleware();
        if (!class_exists($middleware)) {
            list($middleware, $action) = explode("::", $middleware);
        }

        $page = $this->container->get($middleware);

        if ($page->isSecure() && !$this->auth->hasIdentity()) {
            //throw new Exception('User has no permittion!');
            return new RedirectResponse($this->router->gen("login"));
        }
        try {
            return $delegate->process($request);
        } catch (SecurityException $e) {
            return new EmptyResponse(403);
        }
    }
}
