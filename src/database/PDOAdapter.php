<?php
namespace ON\database;

class PDOAdapter {
  protected $config = null;
  protected $_connection = null;
  function __construct ($config) {
    $this->config = $config;
  }
  public function getConnection () {
    if ($this->_connection) {
      return $this->_connection;
    }
    try {
      $this->_connection = new \PDO($this->config['dsn'], $this->config['username'], $this->config['password']);
    } catch(Exception $e) {
      die($e->getMessage());
    }
    if (isset($this->config['init_query'])) {
      $this->_connection->query($this->config['init_query']);
    }
    return $this->_connection;
  }
}

?>