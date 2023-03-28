<?php
namespace ON\Container;

use Invoker\Invoker;

class Executor extends Invoker implements ExecutorInterface {
    public function execute($callable, array $parameters = array()) {
        return $this->call($callable, $parameters);
    }
}