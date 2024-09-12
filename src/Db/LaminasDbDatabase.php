<?php
namespace ON\Db;

use ON\Db\DatabaseInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Adapter;

class LaminasDbDatabase implements DatabaseInterface {
  protected $adapter;
  protected $parameters;
  protected $container;
  protected string $name;

  public function __construct ($name, $parameters, $container) {
    $this->name = $name;
    $this->parameters = $parameters;
    $this->container = $container;

    $adapter_class = isset($config["adapter_class"])? $config["adapter_class"] : Adapter::class;
    $adapter = new $adapter_class($parameters);
    $this->adapter = $adapter;
  }

  protected function getAdapterClass () {
    $config = $this->parameters;
    return isset($config["adapter_class"])? $config["adapter_class"] : Adapter::class;
  }

  public function getConnection() {
    return $this->adapter;
  }

  public function getResource() {
    return $this->adapter->getDriver()->getConnection()->getResource();
  }

  public function getName(): string {
    return $this->name;
  }

  public function setName(string $name): void {
    $this->name = $name;
  }
}