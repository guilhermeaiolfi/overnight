<?php
namespace ON;

class Router extends \Aura\Router\Router {
  protected $application = null;

  public function setApplication($app) {
    $this->application = $app;
  }
  public function getBaseHref() {
    $basePath = str_replace('\\', '/', $this->application->getConfig("paths.base_url_subdir"));
    $request = $this->application->request;
    $port = $request->getUrlPort();
    $scheme = $request->getUrlScheme();
    $urlHost = preg_replace('/\]\:' . preg_quote($port, '/') . '$/', '', $request->getUrlHost()) . ($request->isPortNecessary($scheme, $port) ? ':' . $port: '');
    if(substr($basePath, -1, 1) != '/') {
      $basePath .= '/';
    }
    return $scheme . "://" . $urlHost . $basePath;
  }

  public function addRoute($name, $route) {
    $r = $this->add($name, $route["pattern"]);
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