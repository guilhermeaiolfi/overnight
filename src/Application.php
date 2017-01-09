<?php
namespace ON;

use ON\controller\ControllerResolver;
use ON\middleware\RouterMiddleware;
use ON\middleware\ViewMiddleware;
use ON\middleware\ExecutionMiddleware;
use ON\middleware\SecurityMiddleware;
use ON\middleware\ExceptionMiddleware;

class Application {

  public $container = null;
  public $request = null;
  public $response = null;
  public $router = null;
  private $_models = array();
  protected $kernel = null;
  public $user = array();
  public $dispatcher = null;
  public $pipe = array();
  public $config = null;

  public function __construct ($app_config = null) {
    $this->setupConfig($app_config);
    $this->executeBootloaders();
    $this->pipe = $this->config->get('middlewares');
  }

  public function executeBootloaders () {
    $bootloaders = $this->config->get('bootloaders');
    if (is_array($bootloaders)) {
      foreach ($bootloaders as $bootloader) {
        $bootloader = new $bootloader();
        call_user_func($bootloader, $this);
      }
    }
  }

  private function setupConfig($app_config) {

    if (!$app_config) {
      //TODO: loads a default one
    }
    if (!is_array($app_config)) {
        $app_config = include ($app_config);
    }

    $cachedConfigFile = $app_config["paths"]["base"] . "app/" . 'data/cache/app_config.php';

    if (is_file($cachedConfigFile)) {
        // Try to load the cached config
        $config = include $cachedConfigFile;
    } else {
      // Load configuration from autoload path
      $files = glob($app_config["paths"]["config"] . $app_config["config_glob_paths"], GLOB_BRACE);
      $config = [];
      foreach ($files as $file) {
        $file_config = include ($file);
        $config = \Zend\Stdlib\ArrayUtils::merge($config, $file_config);
      }
      // Cache config if enabled
      if (isset($config["config_cache_enabled"]) && $config["config_cache_enabled"] === true) {
        file_put_contents($cachedConfigFile, '<?php return ' . var_export($config, true) . ';');
      }
    }
    $this->config = new \ON\config\Config($config);
  }

  public function pipe($middleware, $priority = 100) {
    $this->pipe[] = array("middleware" => $middleware, "priority" => $priority);
    return $this;
  }

  public function setContainer($container) {
    $this->container = $container;
  }

  public function getContainer() {
    return $this->container;
  }

  public function getPath($name) {
    return $this->config->get("paths." . $name);
  }

  public function getDbManager($name = 'default') {
    $config = $this->config->get("db" .  "." . "$name");
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
    return $this->_models[$full_class] = $this->getContainer()->make($full_class);
  }

  public function setRouter($router) {
    $this->router = $router;
  }

  public function getRouter() {
    return $this->router;
  }

  public function runAction($config, $request) {
    if (is_array($config)) {
      $route = $this->getRouter()->map->getRoute($config["route"]);
      $request = $request->withAttribute("_route", $route);

      return $this->run($request);
    }


    ob_start();
    include $config;
    $content = ob_get_contents();
    ob_end_clean();

    $response = new Response();
    $response->setBody($content);
    return $response;
  }

  public function run($request = null)
  {
      if (null === $request) {
          $this->request = $request = \Zend\Diactoros\ServerRequestFactory::fromGlobals(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES
        );
      }
      $response = new \Zend\Diactoros\Response();

      $this->sortPipe();
      $relay = new \Relay\RelayBuilder(function ($class) {
        if (is_array($class)) {
          return $this->container->get($class["middleware"]);
        } else {
          return $this->container->get($class);
        }
      });
      $dispatcher = $relay->newInstance($this->pipe);

      $response = $dispatcher($request, $response);
      return $response;
      //$this->terminate($request, $response);
  }

  public function sortPipe() {
    usort($this->pipe, function($a, $b)
    {
        if (!isset($a["priority"]) || !isset($b["priority"]) || $a["priority"] == $b["priority"])
        {
            return 0;
        }
        else if ($a["priority"] > $b["priority"])
        {
            return -1;
        }
        else {
            return 1;
        }
    });
  }
};

?>