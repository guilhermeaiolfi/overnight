<?php
namespace ON\Router;

use Laminas\Diactoros\Response\EmptyResponse;
use ON\Action;
use ON\Application;
use ON\Auth\AuthenticationServiceInterface;
use ON\Common\ViewBuilderTrait;
use ON\Config\AppConfig;
use ON\Container\Executor\ExecutorInterface;
use ON\Exception\SecurityException;
use ON\Extension\PipelineExtension;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

class ActionMiddlewareDecorator implements MiddlewareInterface
{
    use ViewBuilderTrait;
    protected mixed $instance = null;

    protected ExecutorInterface $executor;

    protected ?string $className = null;
    protected string $method = "index";

    public function __construct(
        protected ContainerInterface $container,
        public readonly string $middlewareName,
    )
    {
        if (is_string($middlewareName) && strpos($middlewareName, "::") !== FALSE) {

            [$className, $method] = explode("::", $middlewareName);

            $this->className = $className;
            $this->method = $method;

            $this->instance = $this->container->get($className);
        }

        $this->executor = $this->container->get(ExecutorInterface::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->execute($request, $handler);
    }

    public function execute(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        $args = [
            ServerRequestInterface::class => $request,
            RequestHandlerInterface::class => $handler
        ];
        $action_response = $this->executor->execute([$this->instance, $this->method], $args);

        return $this->buildView($this->instance, $this->method, $action_response, $request, $handler);

    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function validate(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {

        $page = $this->instance;

        $validateMethod = $this->method . 'Validate';
        if(!method_exists($page, $validateMethod)) {
            $validateMethod = 'validate';
        }
        if (!method_exists($page, $validateMethod)) {
            $validateMethod = 'defaultValidate';
        }

        if (method_exists($page, $validateMethod)) {
            $args = [
                ServerRequestInterface::class => $request
            ];
            $valid = $this->executor->execute([$page, $validateMethod], $args);

            if ($valid) {
                return $handler->handle($request, $handler);
            }
            // if it's not validated, we need to handle the error response
            $handleErrorMethod = "handleError";
            if (!method_exists($page, $handleErrorMethod)) {
                $handleErrorMethod = "defaultHandleError";
            }
            $response = $this->executor->execute([$page, $handleErrorMethod], $args);

            return $this->buildView($page, $this->method, $response, $request, $handler);
        }
        return $handler->handle($request, $handler);
    }

    public function loggedCheck(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        
        $page = $this->instance;

        $auth = $this->container->get(AuthenticationServiceInterface::class);

        if (!$auth) {
            throw new \Exception("SecurityMiddleware needs an AuthenticationServiceInterface registered in the container");
        }

        if ($page->isSecure() && !$auth->hasIdentity()) {
            $config = $this->container->get(AppConfig::class);
            //throw new Exception('User has no permittion!');
            return $this->processForward($config->get('controllers.login'), $request);
            //return new RedirectResponse($this->router->gen("login"));
        }

        return $handler->handle($request, $handler);
    }

    public function processForward($middleware, $request): ResponseInterface {
        $result = $request->getAttribute(RouteResult::class);
        $matched = $result->getMatchedRoute();

        $app = $this->container->get(Application::class);

        /** @var PipelineExtension $pipeline */
        $pipeline = $app->pipeline;

        $request = $pipeline->prepareRequest($matched->getPath(), $middleware, $matched->getAllowedMethods(), $matched->getName());

        return $app->runAction($request);
    }

    public function checkPermissions(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $page = $this->instance;

        $checkPermissionsMethod = 'check' . ucfirst($this->method) . 'Permissions';
        if(!method_exists($page, $checkPermissionsMethod)) {
            $checkPermissionsMethod = 'checkPermissions';
        }

        if(!method_exists($page, $checkPermissionsMethod)) {
            $checkPermissionsMethod = 'defaultCheckPermissions';
        }
        // TODO: do we need to wrap this in a try/catch block? what happens if an exception is thrown in checkPermissions()?
        if(method_exists($page, $checkPermissionsMethod)) {
            $args = [
                ServerRequestInterface::class => $request
            ];
            $ok = $this->executor->execute([$page, $checkPermissionsMethod], $args);
            if($ok) {
                return $handler->handle($request);
            } else {
                $config = $this->container->get(AppConfig::class);
                $middleware = $config->get('controllers.errors.403', false);
                if (!$middleware) {
                    return new EmptyResponse(403);
                }
                return $this->processForward($middleware, $request);
            }
        }
        return $handler->handle($request, $handler);
    }
}