<?php
namespace ON\Common;

trait ViewBuilderTrait {
  public function buildView($page, $response, $request, $delegate) {
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
        return $view->{$view_method . 'View'}($request, $delegate);
    }
    // in case it is something else, returns the object
    // it could be a redirect or response object
    return $response;
  }
}