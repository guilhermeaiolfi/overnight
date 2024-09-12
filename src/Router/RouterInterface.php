<?php

namespace ON\Router;

use Mezzio\Router\RouterInterface as MezzioRouterInterface;

interface RouterInterface extends MezzioRouterInterface {
  public function gen($name = null, $params = [], $options = []);
  public function getBasePath();
}