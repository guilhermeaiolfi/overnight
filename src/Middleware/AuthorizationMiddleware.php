<?php

namespace ON\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use Mezzio\Helper\UrlHelper;
use Laminas\Diactoros\Response\RedirectResponse;
use ON\Auth\AuthenticationServiceInterface;
use ON\Auth\AuthorizationServiceInterface;
use ON\Container\ExecutorInterface;
use ON\Exception\SecurityException;
use ON\Action;
use ON\User\UserInterface;

class AuthorizationMiddleware implements MiddlewareInterface
{
  /**
   * @var ContainerInterface|null
   */
  protected $container;

  protected $auth;

  protected $executor;

  /**
   * @param RouterInterface $router
   * @param ResponseInterface $responsePrototype
   * @param ContainerInterface|null $container
   */
  public function __construct(
      AuthenticationServiceInterface $auth,
      ExecutorInterface $executor,
      AuthorizationServiceInterface $authorizationService,
      ContainerInterface $container = null
  ) {
    $this->auth = $auth;
    $this->container = $container;
    $this->authorizationService = $authorizationService;
    $this->executor = $executor;
  }

  /**
   * @param ServerRequestInterface $request
   * @param RequestHandlerInterface $handler
   * @return ResponseInterface
   */
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $action = $request->getAttribute(Action::class, false);
    if (!$action) {
      return $handler->handle($request, $handler);
    }

    $page = $action->getPageInstance();

    $checkPermissionsMethod = 'check' . ucfirst($request->getMethod()) . 'Permissions';
    if(!method_exists($page, $checkPermissionsMethod)) {
      $checkPermissionsMethod = 'checkPermissions';
    }

    if(!method_exists($page, $checkPermissionsMethod)) {
      $checkPermissionsMethod = 'defaultCheckPermissions';
    }
    // TODO: do we need to wrap this in a try/catch block? what happens if an exception is thrown in checkPermissions()?
    $args = [$this->auth, $this->authorizationService, $request];
    $result = $this->executor->execute([$page, $checkPermissionsMethod], $args);
    if($result) {//$page->$checkPermissionsMethod($this->auth, $request)) {
      return $handler->handle($request);
    } else {
      // TODO: allow actions to handle this case e.g. through handleDenial() or something like that?
      // this exception will bubble up to the security filter and cause a forward to the "secure" action there
      throw new SecurityException();
    }
  }
}