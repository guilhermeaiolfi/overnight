<?php
namespace ON\Db;

use ON\Db\DatabaseInterface;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Adapter\Adapter;

class ZendDbDatabase implements DatabaseInterface {
  protected $adapter;
  protected $parameters;
  protected $container;

  public function __construct ($name, $parameters, $container) {
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
}