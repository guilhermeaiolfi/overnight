<?php
namespace ON\view;

use Zend\Diactoros\ServerRequest;
trait View {
  public function setupView($layout_name, $params = null) {
    $app = $this->application;
    $layout_config = $app->config->get('output_types.html.layouts.' . $layout_name);
    $renderer_name = isset($params['renderer'])? $params['renderer'] : $layout_config['renderer'];
    $renderer = $app->config->get('output_types.html.renderers.' . $renderer_name);

    $renderer_class = isset($renderer['class'])? $renderer['class'] : '\ON\view\Renderer';

    $view = $this->application->getContainer()->get($renderer_class);
    $view->setAttributesByRef($this->attributes);
    $view->setBasePath($app->config->get("paths.base"));
    if ($assigns = $renderer['assigns']) {
      foreach($assigns as $key => $assign_key) {
        $view->setAssign($assign_key, $this->application->$key);
      }
    }
    $slots = array();
    if (isset($layout_config["slots"])) {
      foreach($layout_config["slots"] as $slot_name => $slot_config) {
        if (is_array($slot_config)) {
          if (!isset($slot_config["renderer"])) {
            $slot_config["renderer"] = $renderer_name;
          }
          $request = new ServerRequest();
          $response = $this->application->runAction($slot_config, $request);
          $content = $response->getBody();
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
}
?>