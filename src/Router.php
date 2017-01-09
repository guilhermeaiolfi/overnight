<?php
namespace ON;

use Aura\Router\RouterContainer;


class Router extends \Aura\Router\RouterContainer {
  protected $application = null;
  protected $routerContainer = null;
  protected $matcher = null;
  public $map = null;

  public function setApplication($app) {
    $this->application = $app;
  }
  public function matchRequest($request) {
    //$url = /' . ltrim($_SERVER('REQUEST_URI'), PHP_URL_PATH);
    //$url = str_replace($url, $this->getBaseUrl(), "/");
    //echo $request->getUri()->getPath();exit;
    $uri = '/' . ltrim($request->getUri()->getPath(), '/');
    return $this->getMatcher()->match($request);
  }
  public function getBaseHref() {
    $basePath = str_replace('\\', '/', $this->application->config->get("paths.base_url_subdir"));
    $request = $this->application->request;
    $port = $request->getUri()->getPort();
    $scheme = $request->getUri()->getScheme()? "https" : "http";
    $urlHost = preg_replace('/\]\:' . preg_quote($port, '/') . '$/', '', $request->getUri()->getHost()) . ($this->isPortNecessary($scheme, $port) ? ':' . $port: '');
    if(substr($basePath, -1, 1) != '/') {
      $basePath .= '/';
    }
    return $scheme . "://" . $urlHost . $basePath;
  }

  public function isPortNecessary ($schema, $port) {
    return ($schema === "https" && $port != 443) || ($schema === 'http' && $port != 80);
  }

  public function getBaseUrl () {
    $request = \Zend\Diactoros\ServerRequestFactory::fromGlobals($_SERVER);
    $base = $request->getServerParams()['SCRIPT_NAME'];
    $pos = strpos($base, "www/");
    $base = substr($base, 0, $pos);

    //$base = $this->application->getConfig("paths.base_url_subdir");
    return $base = substr($base, -1) == "/"? substr($base, 0, -1) : $base;
  }

  public function generate($name, $params = array()) {
    return $this->getBaseUrl() . parent::generate($name, $params);
  }

  public function addRoute($name, $params) {
    $route = $this->getMap()->route($name, $params["pattern"]);
    foreach ($params as $key => $values) {
      if (in_array($key, array('tokens', 'values', 'server', 'accept')))
      {
        $method = $key;
        $route->$method($values);
        $params[$key] = null;
      }
      else if (in_array($key, array('secure', 'wildcard', 'routable', 'isMatchCallable', 'generateCallable')))
      {
        $method = $key;
        $route->$method($values->toArray());
        $params[$key] = null;
      }
    }
    $route->extras($params);
  }
  public function addRoutes($routes) {
    if (!$routes) {
      return;
    }
    foreach ($routes as $name => $route) {
      $this->addRoute($name, $route);
    }
    return $this;
  }
}
?>