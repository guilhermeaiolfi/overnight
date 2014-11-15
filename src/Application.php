<?php
namespace ON;

use \Aura\Router\RouterFactory;

class Application {
  protected $config = array();
  protected $injector = null;
  public $context = null;

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

    $router = $this->injector->make('\ON\Router');
    if ($routes = $this->getConfig('routes')) {
      $router->addRoutes($routes);
    }

    $injector->prepare('\ON\Context', function($obj) use ($router) {
      $obj->setRouter($router);
    });

    $context = $injector->make('\ON\Context');
    $this->context = $context;
    $injector->share($context);
  }

  public function getRouter() {
    return $this->context->getRouter();
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
    $route = $this->context->getRouter()->match($url, $_SERVER);

    if (! $route) {
        // no route object was returned
        echo "No application route was found for that URL path.";
        exit();
    }

    $content = $this->context->runAction($route->params, $this->context->request);
    if ($content)
    {
      echo $content;
    }
  }
}

?>