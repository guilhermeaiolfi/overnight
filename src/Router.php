<?php
namespace ON;

class Router extends \Aura\Router\Router {
  protected $application = null;

  public function setApplication($app) {
    $this->application = $app;
  }
  public function matchRequest($request) {
    $url = parse_url($request->server->get('REQUEST_URI'), PHP_URL_PATH);
    return $this->match($url, $request->server->all());
  }
  public function getBaseHref() {
    $basePath = str_replace('\\', '/', $this->application->getConfig("paths.base_url_subdir"));
    $request = $this->application->request;
    $port = $request->getPort();
    $scheme = $request->isSecure()? "https" : "http";
    $urlHost = preg_replace('/\]\:' . preg_quote($port, '/') . '$/', '', $request->getHost()) . ($this->isPortNecessary($scheme, $port) ? ':' . $port: '');
    if(substr($basePath, -1, 1) != '/') {
      $basePath .= '/';
    }
    return $scheme . "://" . $urlHost . $basePath;
  }

  public function isPortNecessary ($schema, $port) {
    return ($schema === "https" && $port != 443) || ($schema === 'http' && $port != 80);
  }
  public function addRoute($name, $route) {
    $base = $this->application->request->getScriptName();
    $pos = strpos($base, "www/");
    $base = substr($base, 0, $pos);

    //$base = $this->application->getConfig("paths.base_url_subdir");
    $base = substr($base, -1) == "/"? substr($base, 0, -1) : $base;

    $r = $this->add($name, $base . $route["pattern"]);
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