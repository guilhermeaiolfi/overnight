<?php

namespace ON\Router;

interface RouterInterface {
  public function gen($name = null, $params = [], $options = []);
  public function getBasePath();
}