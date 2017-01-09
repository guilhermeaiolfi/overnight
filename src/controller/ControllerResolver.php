<?php

namespace ON\controller;

class ControllerResolver
{
    private $container = null;
    public function __construct($container)
    {
        $this->container = $container;
    }

    public function getController($request)
    {
        $module = $request->attributes->get("_module");
        $page = $request->attributes->get("_page");
        $action = $request->attributes->get("_action");
        $page_class =  $module . "_" . $page . 'Page';

         // instantiate the action class
        $page = $this->container->make($page_class);
        return array($page, $action . "Action");
    }
    public function getArguments(Request $request, $controller)
    {
        return array($request);
    }
}