<?php

namespace ON\Router;

use Mezzio\Router\RouterInterface;

interface StatefulRouterInterface extends RouterInterface {
  public function getRouteResult ($index);
  public function addRouteResult ($route);
  public function getFirstRouteResult ();
  public function getLastRouteResult ();
  public function gen($name = null, $params = [], $options = []);
  public function getBasePath();
}