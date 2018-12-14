<?php
namespace ON\Db;

use ON\Db\DatabaseInterface;

class PdoDatabase implements DatabaseInterface {
  protected $resource;
  protected $connection;
  protected $parameters;
  protected $container;

  public function __construct ($name, $parameters, $container) {
    $this->parameters = $parameters;
    $this->container = $container;
    $dsn = !empty($parameters["dsn"])? $parameters["dsn"] : null;
    $username = !empty($parameters["username"])? $parameters["username"] : null;
    $password = !empty($parameters["password"])? $parameters["password"] : null;
    $options = !empty($parameters["options"])? $parameters["options"] : null;
    $config = $container->get('config');
    try {
      $this->connection = $this->resource = new \PDO($dsn, $username, $password, $options);

      if ($config->get('debug')) {
        $this->connection = $this->resource = new \DebugBar\DataCollector\PDO\TraceablePDO($this->connection);
      }
      // default connection attributes
      $attributes = array(
        // lets generate exceptions instead of silent failures
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
      );

      if(is_array($parameters['attributes'])) {
        foreach((array)$parameters['attributes'] as $key => $value) {
          $attributes[is_string($key) && strpos($key, '::') ? constant($key) : $key] = is_string($value) && strpos($value, '::') ? constant($value) : $value;
        }
      }

      foreach($attributes as $key => $value) {
        $this->connection->setAttribute($key, $value);
      }

      if (isset($parameters["init_queries"]) && is_array($parameters["init_queries"])) {
        foreach((array)$parameters['init_queries'] as $query) {
          $this->connection->exec($query);
        }
      }
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage(), 0, $e);
    }
  }

  public function getConnection() {
    return $this->connection;
  }

  public function getResource() {
    return $this->resource;
  }

  public function setConnection($connection) {
    $this->connection = $connection;
  }

  public function setResource ($resource) {
    $this->resource = $resource;
  }
}