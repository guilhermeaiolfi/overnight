<?php
namespace ON;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Container\ContainerInterface;

use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Router\Route;

use Laminas\Diactoros\Response;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\Middleware\ErrorHandler;
use ON\Event\NamedEvent;
use ON\Extension\ConfigExtension;
use ON\Extension\ExtensionInterface;
use ON\Extension\PipelineExtension;
use ON\Extension\RouterExtension;
use ON\Router\RouterInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Dotenv\Dotenv;

class Application implements MiddlewareInterface, RequestHandlerInterface {

    public RequestStack $requestStack;
    protected RequestHandlerRunnerInterface $runner;
    public EventDispatcherInterface $eventDispatcher;

    protected string $project_dir;

    protected array $extensions = [];

    protected array $installedExtensions = [];

    protected array $__methods = [];

    protected array $__properties = [];

    private static ContainerInterface $container;
    /**
     * @param array {
     *   debug?: ?bool,
     *   env?: ?string,
     *   disable_dotenv?: ?bool,
     *   project_dir?: ?string,
     *   prod_envs?: ?string[],
     *   dotenv_path?: ?string,
     *   test_envs?: ?string[],
     *   use_putenv?: ?bool,
     *   runtimes?: ?array,
     *   error_handler?: string|false,
     *   env_var_name?: string,
     *   debug_var_name?: string,
     *   dotenv_overload?: ?bool,
     * } $options
     */

    public function __construct(
        protected ?array $options = null
    ) {
        
        $this->project_dir = $project_dir = $options["project_dir"]?? dirname(getcwd(), 1);
        
        // defines the default dir to the project root
        if ($project_dir != getcwd()) {
            chdir($project_dir);
        }

        // load .env file vars
        $dotenv = new Dotenv();
        $dotenv->load($project_dir.'/.env');

        
        $this->options["debug"] = $_ENV["APP_DEBUG"] = $options["debug"]?? (bool) $_ENV["APP_DEBUG"];
        
        $this->requestStack = new RequestStack();
        
        $this->loadExtensions();

        $this->runner = self::getContainer()->get(RequestHandlerRunner::class);
    }

    public function isDebug() {
        return $this->options["debug"];
    }

    public function ext($name) {
        return $this->getExtension($name);
    }

    public function getInstalledExtensions() {
        return $this->installedExtensions;
    }

    protected function loadExtensions() {
        $extensions = require_once ("config/extensions.php");
        foreach ($extensions as $extension) {
            $this->install($extension);
        }

        if ($this->hasExtension('events')) {
            $this->ext('events')->dispatch(new NamedEvent("core.ready"));
        }
    }

    public function install(string $extension_class) {

        if (!class_exists($extension_class)) {
            throw new Exception("It was passed an invalid class as extension.");
        }
        $interfaces = class_implements($extension_class);

        if (isset($interfaces['ON\Extension\ExtesionInterface'])) {
            throw new Exception("Extensions must implement \ON\Extension\ExtesionInterface.");
        }

        $this->extensions[$extension_class] = $extension_class::install($this);
        $this->installedExtensions[] = $extension_class;
    }

    public function registerExtension($name, $obj): void {
        $this->extensions[$name] = $obj;
    }

    public function hasExtension($class): bool {
        return array_key_exists($class, $this->extensions);
    }

    public function getExtension(string $class): ExtensionInterface {
        if (!$this->hasExtension($class)) {
            throw new Exception("Extension {$class} is not installed.");
        }
        return $this->extensions[$class];
    }

    /**
     * Returns currently active container scope if any.
     *
     * @return null|ContainerInterface
     */
    public static function getContainer(): ?ContainerInterface
    {
        return self::$container;
    }

    public static function setContainer($container) {
        self::$container = $container;
    }
    

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->extensions[PipelineExtension::class]->handle($request);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->extensions[PipelineExtension::class]->process($request, $handler);
    }

    public function pipe($middlewareOrPath, $middleware = null): void
    {
        $this->extensions[PipelineExtension::class]->pipe($middlewareOrPath, $middleware);
    }

    /**
     * Run the application.
     *
     * Proxies to the RequestHandlerRunner::run() method.
     */
    public function run(): void
    {
        // send run 
        if ($this->hasExtension('events')) {
            /** @var \ON\Extension\EventsExtension $router_ext */
            $this->ext('events')->dispatch(new NamedEvent("core.run"));
        }
        $this->runner->run();
    }

    public function route(string $path, $middleware, ?array $methods = null, ?string $name = null): Route
    {
        /** @var \ON\Extension\RouterExtension $router_ext */
        $router_ext = $this->ext('router');
        return $router_ext->route($path, $middleware, $methods, $name);
    }

    public function runAction ($request, $response = null) {
        $response = $response ?: new Response();
        return $this->handle($request);
    }

    public function __get($name)
    {
        return $this->__properties[$name];
    }

    public function __set($name, $value)
    {
        $this->__properties[$name] = $value;
    }

    public function __call ($name, $args)    {
        return call_user_func_array ($this->__methods[$name], $args); 
    }

    public function registerMethod($name, callable $method) {
        $this->__methods[$name] = $method;
    }
}