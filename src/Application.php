<?php
namespace ON;

use \Aura\Router\RouterFactory;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use ON\controller\ControllerResolver;
use ON\eventlisteners\RouterListener;
use ON\eventlisteners\ViewListener;
use ON\eventlisteners\SecurityListener;
use ON\eventlisteners\ExceptionListener;


class Application implements HttpKernelInterface {
  protected $config = array();
  protected $injector = null;
  public $request = null;
  public $response = null;
  public $router = null;
  private $_models = array();
  protected $kernel = null;
  public $user = array();
  public $dispatcher = null;

  public function __construct ($config) {

    if (is_array($config)) {
      $this->config = $config;
    } else {
      $this->loadConfigFiles($config);
    }
    $injector = $this->getConfig('di');

    $this->injector = $injector = $injector? $injector : new \Auryn\Provider();
    $injector->share($this);
    $injector->share($injector);

    $self = $this;
    $injector->prepare('\ON\Router', function($obj) use ($self) {
      $obj->setApplication($self);
    });

    $this->router = $router = $this->injector->make('\ON\Router');
    if ($routes = $this->getConfig('routes')) {
      $this->router->addRoutes($routes);
    }

    $this->dispatcher = $dispatcher = $this->injector->make('EventDispatcher');
    $injector->share($dispatcher);

    $this->controller_resolver = $resolver = $this->injector->make('ControllerResolver');
    $injector->share($resolver);


    $dispatcher->addSubscriber(new RouterListener($router, new RequestStack()));
    $dispatcher->addSubscriber(new ViewListener());

    $user = $this->user;
    $user["authenticated"] = false;

    $dispatcher->addSubscriber(new SecurityListener($user));
    $dispatcher->addSubscriber(new ExceptionListener($this));

    $this->kernel = $this->injector->make('Kernel');
  }

  public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true) {
    $this->request = $request;
    return $this->kernel->handle($request);
  }

  public function loadConfigFiles($config_path) {
    $files = glob($config_path . '*.php', GLOB_BRACE);
    $ignore_config = array();
    foreach($files as $file) {
      $content = require_once($file);
      $name = basename($file, ".php");
      if ($content && !in_array($name, $ignore_config)) {
        $this->config[$name] = $content;
      }
    }
  }
  public function setInjector($injector) {
    $this->injector = $injector;
  }
  public function getInjector() {
    return $this->injector;
  }
  public function getPath($name) {
    return $this->config["paths"][$name];
  }
  public function getConfig($path, $default = null) {
    $current = $this->config;
    $p = strtok($path, '.');

    while ($p !== false) {
      if (!isset($current[$p])) {
        return $default;
      }
      $current = $current[$p];
      $p = strtok('.');
    }
    return $current;
  }
  public function setConfig($path, $value) {
    $current = $this->config;
    $p = strtok($path, '.');

    while ($p !== false) {
      if (!isset($current[$p])) {
        $current[$p] = array();
      }
      $current[$p] = $current;
      $p = strtok('.');
    }
    $current = $value;
  }

  public function getDbManager($name = 'default') {
    $config = $this->getConfig("db" .  "." . "$name");
    return $this->dbs[$name] = new $config["adapter_class"]($config);
  }
  public function getDbConnection ($name = 'default') {
    return $this->getDbManager($name)->getConnection();
  }
  public function getModel($module, $class) {
    $full_class = $module . "_" . $class;
    if (isset($this->_models[$full_class]))
    {
      return $this->_models[$full_class];
    }
    return $this->_models[$full_class] = $this->getInjector()->make($full_class);
  }
  public function setRouter($router) {
    $this->router = $router;
  }
  public function getRouter() {
    return $this->router;
  }
  public function runAction($config, $request) {
    if (is_array($config)) {
      $page_class = $config["module"] . "_" . $config["page"] . 'Page';
       // instantiate the action class
      $page = $this->getInjector()->make($page_class);
      $request->attributes->add(array("_module" => $config["module"],
                                      "_action" => $config["action"],
                                      "_page" => $config["page"]));

      return $this->kernel->handle($request);
    }


    ob_start();
    include $config;
    $content = ob_get_contents();
    ob_end_clean();

    $response = new Response();
    $response->setContent($content);
    return $response;
  }
  /**
   * Handles the request and delivers the response.
   *
   * @param Request|null $request Request to process
   */
  public function run(Request $request = null)
  {
      if (null === $request) {
          $request = Request::createFromGlobals();
      }
      $response = $this->handle($request);
      $response->send();
      $this->terminate($request, $response);
  }
}

?>