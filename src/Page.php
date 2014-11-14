<?php
namespace ON;

class Page implements IPage {
  use \ON\AttributeHolder;

  protected $application = null;
  protected $container = null;

  public function __construct (Application $app, Container $container) {
    $this->application = $app;
    $this->container = $container;
  }
  public function setupView($layout_name, $params = null) {

    $app = $this->application;
    $layout_config = $app->getConfig('layouts.' . $layout_name);

    $view = $this->application->getInjector()->make('Renderer');
    $view->setAttributesByRef($this->attributes);
    $view->setBasePath($app->getConfig("paths.base"));
    //$app->getPath("base") . $template);
    $slots = array();
    if (isset($layout_config["slots"])) {
      foreach($layout_config["slots"] as $slot_name => $slot_config) {
        if (is_array($slot_config)) {
          $content = $this->container->runAction($slot_config, $this->container->request);
        }
        else {
          $content = $view->getTemplateContent($slot_config);
        }
        $view->setSlot($slot_name, $content);
      }
    }
    $view->setLayout($layout_config['file']);
    return $view;
  }
  public function setContainer($container) {
    $this->container = $container;
  }
  public function getContainer($container) {
    return $this->container;
  }
};
?>