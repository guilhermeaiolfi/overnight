<?php

namespace ON\middleware;


class ViewMiddleware
{
    private $controller = null;

    public function __construct (\ON\Application $app) {
        $this->app = $app;
    }

    public function __invoke($request, $response, $next)
    {
        $page = $request->getAttribute('_page');
        $response = $next($request, $response);
        //print_r($response);exit;

        if (is_string($response)) {
            $view_method = $response;
            $view = $page;
            if (strpos($view_method, ":") !== FALSE) {
              $view_method = explode(":", $view_method);
              $view = $this->application->getInjector()->make($view_method[0] . 'Page');
              $view_method = $view_method[1];
              $view->setAttributes($page->getAttributes());
            }

            $content = $view->{$view_method . 'View'}($request);

            $response = $response->writeBody($content);
        }
        return $response;
    }
}
?>