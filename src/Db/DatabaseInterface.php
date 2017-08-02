<?php
namespace ON\Db;

interface DatabaseInterface {
  public function __construct ($name, $parameters, $container);
  public function getConnection();
  public function getResource();
}