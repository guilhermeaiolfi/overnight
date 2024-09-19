<?php

declare(strict_types=1);

namespace ON\Config\Provider;

use Laminas\ConfigAggregator\GlobTrait;
use Generator;
use ON\Config\Provider\ResourceProviderInterface;
use ON\Config\Resource\GlobMTimeResource;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\Config\Resource\ResourceInterface;

/**
 * Provide a collection of PHP files returning config arrays.
 */
class GlobProvider implements ResourceProviderInterface
{
    use GlobTrait;

    /**
     * @param non-empty-string $pattern A glob pattern by which to look up config files.
     */
    public function __construct(protected string $prefix, protected string $pattern)
    {
    }

    /**
     * @return Generator
     */
    public function __invoke()
    {
        foreach ($this->glob($this->prefix . $this->pattern) as $file) {
            yield include $file;
        }
    }

    public function getResource(): ResourceInterface {
        return new GlobMTimeResource($this->prefix, $this->pattern, true);
    }
}
