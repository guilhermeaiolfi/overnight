<?php
namespace ON\Container\Executor;

use Psr\Container\ContainerInterface;

interface ExecutorInterface {
  public function execute($callableOrMethodStr, array $args = array());
  public function getContainer(): ?ContainerInterface;
}