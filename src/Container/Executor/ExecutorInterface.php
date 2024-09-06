<?php
namespace ON\Container\Executor;

interface ExecutorInterface {
  public function execute($callableOrMethodStr, array $args = array());

}