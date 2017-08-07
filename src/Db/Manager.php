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
      return $database->getConnection();
    }
    return null;
  }

  public function getDatabaseResource ($name = null) {
    $database = $this->getDatabase($name);
    if ($database) {
      return $database->getResource();
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

    $database = null;
    if (isset($this->instances[$name])) {
      return  $this->instances[$name];
    }
    $db_config = $config["db"]["databases"][$name];
    $database_class = null;
    if (isset($db_config["class"])) {
      $database_class = $db_config["class"];
    } else {
      throw new \Exception ("There is no \"class\" defined for " . $name . " database configuration");
    }

    $database = new $database_class($name, $db_config, $this->c);

    // register the connection in the DataCollector of DebugBar
    // for debugging purposes
    if ($config["debug"] && $this->c->has(\DebugBar\DebugBar::class)) {
      $connection = $database->getResource();
      if ($connection instanceof \PDO) {
        $pdo = new \DebugBar\DataCollector\PDO\TraceablePDO($connection);
        $debugbar = $this->c->get(\DebugBar\DebugBar::class);
        $collector = $debugbar->hasCollector("pdo")? $debugbar->getCollector("pdo") : null;
        if (!$collector) {
          $collector = new \DebugBar\DataCollector\PDO\PDOCollector();
          $debugbar->addCollector($collector);
        }
        $collector->addConnection($pdo, $name);
      }
    }
    return $this->instances[$name] = $database;
  }
}