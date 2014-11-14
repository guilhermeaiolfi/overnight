<?php
namespace ON;

class Router extends \Aura\Router\Router {
  protected $application = null;

  public function setApplication($app) {
    $this->application = $app;
  }
  public function getBaseHref() {
    $basePath = str_replace('\\', '/', $this->application->getConfig("paths.base_url_subdir"));
    return $_SERVER["REQUEST_SCHEME"] . "://" . $_SERVER["HTTP_HOST"] . $basePath . "/";
  }
}
?>