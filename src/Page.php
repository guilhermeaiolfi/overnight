<?php
namespace ON;

class Page implements IPage {
  use \ON\AttributeHolder;

  protected $application = null;
  protected $context = null;

  public function __construct (Application $app) {
    $this->application = $app;
    $this->context = $app->context;
  }
  public function setupView($layout_name, $params = null) {
    $app = $this->application;
    $layout_config = $app->getConfig('output_types.html.layouts.' . $layout_name);
    $renderer_name = isset($params['renderer'])? $params['renderer'] : $layout_config['renderer'];
    $renderer = $app->getConfig('output_types.html.renderers.' . $renderer_name);

    $renderer_class = isset($renderer['class'])? $renderer['class'] : '\ON\Renderer';

    $view = $this->application->getInjector()->make($renderer_class);
    $view->setAttributesByRef($this->attributes);
    $view->setBasePath($app->getConfig("paths.base"));
    if ($assigns = $renderer['assigns']) {
      foreach($assigns as $key => $assign_key) {
        $view->setAssign($assign_key, $this->context->$key);
      }
    }
    $slots = array();
    if (isset($layout_config["slots"])) {
      foreach($layout_config["slots"] as $slot_name => $slot_config) {
        if (is_array($slot_config)) {
          if (!isset($slot_config["renderer"])) {
            $slot_config["renderer"] = $renderer_name;
          }
          $content = $this->context->runAction($slot_config, $this->context->request);
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
  public function setContext($context) {
    $this->context = $context;
  }
  public function getContext($context) {
    return $this->context;
  }
};
?>