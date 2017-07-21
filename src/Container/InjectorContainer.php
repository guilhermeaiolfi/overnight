<?php
namespace ON\Container;

use Auryn\Injector;
use Auryn\InjectorException;
use Psr\Container\ContainerInterface;
use Northwoods\Container\NotFoundException;
use Northwoods\Container\ContainerException;

class InjectorContainer implements ContainerInterface, ExecutorInterface
{
    const I_ALL = 31;

    /**
     * @var Injector
     */
    private $injector;

    public function __construct(Injector $injector)
    {
        $this->injector = $injector;
    }

    // ContainerInterface
    public function get($id)
    {
        $config = $this->injector->make("config");
        $config = $config["dependencies"];
        $sharedByDefault = isset($config["shared_by_default"])? $config["shared_by_default"] : true;
        if (($sharedByDefault && !isset($config["shared"][$id]))
             || (isset($config["shared"][$id]) && $config["shared"][$id]))
        {
          $this->injector->share($id);
        }
        if (false === $this->has($id)) {
            throw NotFoundException::classDoesNotExist($id);
        }

        try {
            return $this->injector->make($id);
        } catch (InjectorException $e) {
            throw ContainerException::couldNotMake($id, $e);
        }
    }

    // ContainerInterface
    public function has($id)
    {
        return class_exists($id) || $this->hasReference($id);
    }

    /**
     * Check the injector has a reference
     *
     * @param string $id
     *
     * @return bool
     */
    private function hasReference($id)
    {
        // https://github.com/rdlowrey/auryn/issues/157
        $details = $this->injector->inspect($id, self::I_ALL);
        return (bool) array_filter($details);
    }

    public function execute($callableOrMethodStr, array $args = array()) {
      return $this->injector->execute($callableOrMethodStr, $args);
    }
}