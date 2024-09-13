<?php
namespace ON\Db;

use ON\Event\NamedEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class Manager {
  protected $config;
  protected ContainerInterface $container;

  protected $instances = [];

  protected $eventDispatcher = null;

  public function __construct (
    $config,
    ContainerInterface $c,
  ) {
    $this->config = $config;
    $this->container = $c;
  }

  public function setEventDispatcher (EventDispatcherInterface $dispatcher) {
    $this->eventDispatcher = $dispatcher;
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
    if (!isset($name)) {
      if (isset($this->config["default"])) {
        $name = $this->config["default"];
      } else {
        throw new \Exception("There is no \"default\" DB set and none was given.");
      }
    }

    $database = null;

    // don't creates another one, gets from cache
    if (isset($this->instances[$name])) {
      return  $this->instances[$name];
    }

    $db_config = $this->config["databases"][$name];
    $database_class = null;
    if (isset($db_config["class"])) {
      $database_class = $db_config["class"];
    } else {
      throw new \Exception ("There is no \"class\" defined for " . $name . " database configuration");
    }

    $database = new $database_class($name, $db_config, $this->container);

    if ($this->eventDispatcher) {
      $event = new NamedEvent("core.db.manager.create", $database);
      $this->eventDispatcher->dispatch($event);
    }

    return $this->instances[$name] = $database;
  }
}