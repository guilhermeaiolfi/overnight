<?php
namespace ON\Common;

trait ViewBuilderTrait {
  public function buildView($page, $action_name, $response, $request, $delegate) {
    // if it is a string, it's just a reference to the view to render
    if (is_string($response)) {


      $view_method = $response;
      $view = $page;
      if (strpos($response, ":") !== FALSE) {
        $view_method = explode(":", $response);
        $view = $this->container->get($view_method[0] . 'Page');
        $view->setAttributesByRef($page->getAttributes());
        $view_method = $view_method[1];
      }
      $view_method = strtolower($view_method);

      // sets the adefault template name is case the developer whats to use a convention
      // to $this->render() without any parameters
      $path = explode("\\", get_class($view));
      $view->setDefaultTemplateName(strtolower($path[0] . "::" . str_replace("Page", "", array_pop($path)) . "-" . $action_name . "-" . $view_method));

      return $view->{$view_method . 'View'}($request, $delegate);
    }
    // in case it is something else, returns the object
    // it could be a redirect or response object
    return $response;
  }
}