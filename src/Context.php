<?php
namespace ON;
use Aura\Web\WebFactory;

class Context {
  protected $application = null;
  public $request = null;
  public $response = null;
  public $router = null;
  private $_models = array();
  public function __construct (Application $app) {
    $this->application = $app;
    $web_factory = new WebFactory($GLOBALS);
    $this->request = $web_factory->newRequest();
    $this->response = $web_factory->newResponse();
  }
  public function getDbManager($name = 'default') {
    $config = $this->application->getConfig("db" .  "." . "$name");
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
    return $this->_models[$full_class] = $this->application->getInjector()->make($full_class);
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
    $page = $this->application->getInjector()->make($page_class);
    $request->params->set($config);
    $view_method = $page->{$config["action"] . 'Action'}($request, $this->response);
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