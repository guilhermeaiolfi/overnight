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

use ON\controller\ControllerResolver;
use ON\eventlisteners\RouterListener;
use ON\eventlisteners\ViewListener;
use ON\eventlisteners\SecurityListener;
use ON\eventlisteners\ExceptionListener;


class Application {
  protected $config = array();
  protected $injector = null;
  public $request = null;
  public $response = null;
  public $router = null;
  private $_models = array();
  protected $kernel = null;
  public $user = array();

  public function __construct ($config) {
    $this->request = $request = Request::createFromGlobals();

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

    $this->router = $this->injector->make('\ON\Router');
    if ($routes = $this->getConfig('routes')) {
      $this->router->addRoutes($routes);
    }

    //$injector->make('\ON\request\Request');

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

  public function dispatch($url) {


    $router = $this->router;

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new RouterListener($router, new RequestStack()));
    $dispatcher->addSubscriber(new ViewListener());

    $user = $this->user;
    $user["authenticated"] = false;
    $dispatcher->addSubscriber(new SecurityListener($user));
    $dispatcher->addSubscriber(new ExceptionListener($this));

    $resolver = new ControllerResolver($this->injector);

    // $controller = $resolver->getController($request);
    // $arguments = $resolver->getArguments($request, $controller);

    // $response = call_user_func_array($controller, $arguments);

    $this->kernel = new HttpKernel($dispatcher, $resolver);

    $response = $this->kernel->handle($this->request);

    $response->send();

    $this->kernel->terminate($this->request, $response);
    // $content = $this->runAction($route->params, $this->request);
    // if ($content)
    // {
    //   echo $content;
    // }
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
}

?>