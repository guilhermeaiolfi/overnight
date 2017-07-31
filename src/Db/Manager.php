<?php
namespace ON\Db;

use Zend\Db\Adapter\Adapter;
use Psr\Container\ContainerInterface;

class Manager {
  protected $c;

  protected $instances = [];

  public function __construct (ContainerInterface $c) {
    $this->c = $c;
  }

  public function getDatabaseConnection ($name = null) {
    $database = $this->getDatabase($name);
    if ($database) {
      return $database->getDriver()->getConnection();
    }
    return null;
  }

  public function getDatabaseResource ($name = null) {
    $conn = $this->getDatabaseConnection($name);
    if ($conn) {
      return $conn->getResource();
    }
    return null;
  }

  public function getDatabase ($name = null) {
    $config = $this->c->get("config");
    if (!isset($name)) {
      if (isset($config["db"]["default"])) {
        $name = $config["db"]["default"];
      } else {
        throw new \Exception("There is no \"default\" DB set and none was given.");
      }
    }

    $adapter = null;
    if (isset($this->instances[$name])) {
      $adapter = $this->instances[$name];
    } else {
      $db_config = $config["db"]["adapters"][$name];
      $adapter_class = $this->getAdapterClass($name);
      $adapter = new $adapter_class($db_config);

      // register the connection in the DataCollector of DebugBar
      // for debugging purposes
      if ($config["debug"] && $this->c->has(\DebugBar\DebugBar::class)) {
          $connection = $adapter->getDriver()->getConnection();
          $pdo = new \DebugBar\DataCollector\PDO\TraceablePDO($connection->getResource());
          $debugbar = $this->c->get(\DebugBar\DebugBar::class);
          $collector = $debugbar->hasCollector("pdo")? $debugbar->getCollector("pdo") : null;
          if (!$collector) {
            $collector = new \DebugBar\DataCollector\PDO\PDOCollector();
            $debugbar->addCollector($collector);
          }
          $collector->addConnection($pdo, $name);
      }
      $this->instances[$name] = $adapter;
    }

    return $adapter;
  }

  protected function getAdapterClass ($name) {
    $config = $this->c->get("config");
    $default_class = isset($config["db"]["default_class"])? $config["db"]["default_class"] : Adapter::class;

    return isset($config["db"]["adapters"][$name]) && isset($config["db"]["adapters"][$name]["class"])? $config["db"]["adapters"][$name]["class"] : $default_class;
  }
}