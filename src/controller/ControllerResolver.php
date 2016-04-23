<?php

namespace ON\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

class ControllerResolver implements ControllerResolverInterface
{
    private $injector = null;
    public function __construct($injector)
    {
        $this->injector = $injector;
    }

    public function getController(Request $request)
    {
        $module = $request->attributes->get("_module");
        $page = $request->attributes->get("_page");
        $action = $request->attributes->get("_action");
        $page_class =  $module . "_" . $page . 'Page';

         // instantiate the action class
        $page = $this->injector->make($page_class);
        return array($page, $action . "Action");
    }
    public function getArguments(Request $request, $controller)
    {
        return array($request);
    }
}