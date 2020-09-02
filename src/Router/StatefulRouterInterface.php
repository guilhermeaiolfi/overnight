<?php

namespace ON\Router;

//use Mezzio\Router\RouterInterface;

interface StatefulRouterInterface {
  public function getRouteResult ($index);
  public function addRouteResult ($route);
  public function getFirstRouteResult ();
  public function getLastRouteResult ();
  public function gen($name = null, $params = [], $options = []);
  public function getBasePath();
}