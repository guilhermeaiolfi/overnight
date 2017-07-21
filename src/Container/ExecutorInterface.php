<?php
namespace ON\Container;

interface ExecutorInterface {
  public function execute($callableOrMethodStr, array $args = array());

}