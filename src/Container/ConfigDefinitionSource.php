<?php

namespace ON\Container;

use Adbar\Dot;
use DI\Definition\Definition;
use DI\Definition\Source\DefinitionSource;
use DI\Definition\ValueDefinition;

class ConfigDefinitionSource implements DefinitionSource
{
    public function __construct (protected $config)
    {

    }

    /**
     * Returns the DI definition for the entry name.
     *
     * @throws InvalidDefinition An invalid definition was found.
     */
    public function getDefinition(string $name) : Definition|null
    {
        if ($this->config->has($name)) {
            return new ValueDefinition($this->config->get($name));
        }
        return null;
    }

    public function getDefinitions() : array {
        // TODO: flat the definition object
        return $config = $this->config->all();
        /*unset($config["dependencies"]);*/
        $config = new Dot($config);
        return $config->flatten();
        //return $config->all();*/
    }
}
