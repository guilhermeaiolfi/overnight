<?php
namespace ON;

class Router extends \Aura\Router\Router {
  protected $application = null;

  public function setApplication($app) {
    $this->application = $app;
  }
  public function getBaseHref() {
    $basePath = str_replace('\\', '/', $this->application->getConfig("paths.base_url_subdir"));
    $urlHost = preg_replace('/\]\:' . preg_quote($_SERVER['SERVER_PORT'], '/') . '$/', '', $_SERVER['SERVER_NAME']) . ($this->isPortNecessary($_SERVER["REQUEST_SCHEME"], $_SERVER["SERVER_PORT"]) ? ':' . $_SERVER["SERVER_PORT"] : '');
    if(substr($basePath, -1, 1) != '/') {
      $basePath .= '/';
    }
    return $_SERVER["REQUEST_SCHEME"] . "://" . $urlHost . $basePath;
  }

  // TODO: temporary hack, it should go into the request class after things get more stable
  private function isPortNecessary($schema, $port) {
    return ($schema === "https" && $port != 443) || ($schema === 'http' && $port != 80);
  }
  // TODO: temporary hack, it should go into the request class after things get more stable
  private function getRequestUri() {
    if(isset($_SERVER['UNENCODED_URL']) && isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
      // Microsoft IIS 7 with URL Rewrite Module
      return $_SERVER['UNENCODED_URL'];
    } elseif(isset($_SERVER['HTTP_X_REWRITE_URL']) && isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
      // Microsoft IIS with ISAPI_Rewrite
      return $_SERVER['HTTP_X_REWRITE_URL'];
    } elseif(!isset($_SERVER['REQUEST_URI']) && isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
      return $_SERVER['ORIG_PATH_INFO'] . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    } elseif(isset($_SERVER['REQUEST_URI'])) {
      return $_SERVER['REQUEST_URI'];
    }
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