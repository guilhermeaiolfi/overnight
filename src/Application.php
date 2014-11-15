<?php
namespace ON;

use \Aura\Router\RouterFactory;

class Application {
  protected $config = array();
  protected $router = null;
  protected $injector = null;
  public $context = null;

  public function setupRouter($routes) {
    $router = $this->injector->make('Router');
    if (!$routes) {
      return $router;
    }
    foreach ($routes as $name => $route) {
      $r = $router->add($name, $route["pattern"]);
      foreach ($route as $key => $values) {
        if (in_array($key, array('tokens', 'values', 'server', 'accept')))
        {
          $method = 'add' . ucfirst($key);
          $r->$method($values);
          $route[$key] = null;
        }
        else if (in_array($key, array('secure', 'wildcard', 'routable', 'isMatchCallable', 'generateCallable')))
        {
          $method = 'set' . ucfirst($key);
          $r->$method($values);
          $route[$key] = null;
        }
      }
      $r->addValues($route);
    }
    return $router;
  }

  public function __construct ($config) {
    $this->injector = $injector = new \Auryn\Provider(new \Auryn\ReflectionPool);
    $injector->share($this);
    $injector->share($injector);
    $injector->alias('Injector', '\Auryn\Provider');
    $injector->alias('Router', '\ON\Router');
    $injector->alias('Context', '\ON\Context');
    $injector->alias('Application', '\ON\Application');
    $injector->alias('Renderer', '\ON\Renderer');
    $injector->delegate('Router', ['\Aura\Router\RouterFactory', 'newInstance']);
    // $injector->prepare('IModel', function($obj) {
    //   $obj->setA('porcaria');
    // });
    if (is_array($config)) {
      $this->config = $config;
    } else {
      $this->loadConfigFiles($config);
    }

    $self = $this;
    $injector->prepare('\ON\Router', function($obj) use ($self) {
      $obj->setApplication($self);
    });

    $router = $this->setupRouter($this->getConfig("routes"));

    $injector->prepare('\ON\Context', function($obj) use ($router) {
      $obj->setRouter($router);
    });


    $context = $injector->make('Context');
    $this->context = $context;
    $injector->share($context);
  }

  public function loadConfigFiles($config_path) {
    $files = glob($config_path . '*.php', GLOB_BRACE);
    $ignore_config = array('di');
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