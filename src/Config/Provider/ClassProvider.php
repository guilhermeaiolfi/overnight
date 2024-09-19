<?php

declare(strict_types=1);

namespace ON\Config\Provider;

use Laminas\ConfigAggregator\GlobTrait;
use Generator;
use ON\Config\Provider\ResourceProviderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\ReflectionClassResource;
use Symfony\Component\Config\Resource\ResourceInterface;

/**
 * Provide a collection of PHP files returning config arrays.
 */
class ClassProvider implements ResourceProviderInterface
{
    /**
     * @param non-empty-string $pattern A glob pattern by which to look up config files.
     */
    public function __construct(protected mixed $class)
    {
    }

    public function __invoke()
    {
        $invoker = $this->class;
        if (is_string($this->class)) {
            $invoker = new $this->class();
        }
        return $invoker();
    }

    public function getResource(): ResourceInterface {
        $reflection = new \ReflectionClass(
            is_string($this->class)? 
                $this->class : 
                get_class($this->class)
        );
        return new FileResource($reflection->getFileName());
    }
}
