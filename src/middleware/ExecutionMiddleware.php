<?php

namespace ON\middleware;

class ExecutionMiddleware
{
    protected $app = null;

    public function __construct(\ON\Application $app) {
        $this->app = $app;
    }

    public function __invoke ($request, $response, $next)
    {
        $routeResult = $request->getAttribute("_route", false);
        if (!$routeResult) {
            return $next($request, $response);
        }
        $attributes = $routeResult->extras;
        if (!$attributes["module"] || !$attributes["action"] || !$attributes["page"]) {
            throw new Exception\InvalidMiddlewareException(sprintf(
                'The route %s does not have a controller to dispatch',
                $routeResult
            ));
        }
        $module = $attributes["module"];
        $page = $attributes["page"];
        $action = $attributes["action"];

        $page_class =  $module . "_" . $page . 'Page';

         // instantiate the action class
        $page = $this->app->getContainer()->make($page_class);
        $request = $request->withAttribute("_page", $page);
        $action_response = $page->{$action . "Action"}($request, $response);

         if (is_string($action_response)) {
            $view_method = $action_response;
            $view = $page;
            if (strpos($view_method, ":") !== FALSE) {
              $view_method = explode(":", $view_method);
              $view = $this->application->getContainer()->make($view_method[0] . 'Page');
              $view_method = $view_method[1];
              $view->setAttributes($page->getAttributes());
            }

            $content = $view->{$view_method . 'View'}($request);

            $response->getBody()->write($content);
        }
        return $next($request, $response, $next);
    }
}
?>