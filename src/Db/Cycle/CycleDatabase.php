<?php
namespace ON\Db\Cycle;
use ON\Db\DatabaseInterface;

use Cycle\ORM\ORM;
use Cycle\ORM\Factory;
use Cycle\ORM\Schema;

class CycleDatabase implements DatabaseInterface {
  protected $dbal = null;
  protected $orm = null;
  protected string $name;

  public function __construct ($name, $parameters, $container) {
    $this->name = $name;
    $config = $container->get('config');
    $cycle_config = new \Spiral\Database\Config\DatabaseConfig($parameters);
    $this->dbal = new \Spiral\Database\DatabaseManager($cycle_config);

    $this->orm = new ORM(new Factory($this->dbal));
    $this->orm = $this->orm->withSchema(new Schema($config->get('cycle.schema')));
  }
  public function getConnection() {
    return $this->orm;
  }
  public function getResource() {
    return $this->dbal;
  }
  public function getName(): string {
    return $this->name;
  }

  public function setName(string $name): void {
    $this->name = $name;
  }
}