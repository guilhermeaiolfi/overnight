<?php
namespace ON;

use \Aura\Router\RouterFactory;

class Application {
  protected $config = array();
  protected $injector = null;
  public $request = null;
  public $response = null;
  public $router = null;
  private $_models = array();

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

    $this->router = $this->injector->make('\ON\Router');
    if ($routes = $this->getConfig('routes')) {
      $this->router->addRoutes($routes);
    }

    $this->request = $injector->make('\ON\request\Request');

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

    // get the route based on the path and server
    $route = $this->router->match($url, $_SERVER);

    if (! $route) {
        // no route object was returned
        echo "No application route was found for that URL path.";
        exit();
    }

    $content = $this->runAction($route->params, $this->request);
    if ($content)
    {
      echo $content;
    }
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
    $page_class = $config["module"] . "_" . $config["page"] . 'Page';

     // instantiate the action class
    $page = $this->getInjector()->make($page_class);
    $request->mergeParameters($config);
    $view_method = $page->{$config["action"] . 'Action'}($request);
    $view = $page;
    if (strpos($view_method, ":") !== FALSE) {
      $view_method = explode(":", $view_method);
      $view = $this->application->getInjector()->make($view_method[0] . 'Page');
      $view_method = $view_method[1];
      $view->setAttributes($page->getAttributes());
    }
    return $view->{$view_method . 'View'}($request);
  }
}

?>