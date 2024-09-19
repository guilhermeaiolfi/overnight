<?php

declare(strict_types=1);

namespace ON\Config\Provider;

use Symfony\Component\Config\Resource\ResourceInterface;

/**
 * Provider that returns the array seeded to itself.
 *
 * Primary use case is configuration cache-related settings.
 *
 * @template TKey of array-key
 * @template TValue
 */
class ArrayProvider implements ResourceProviderInterface
{
    /**
     * @param array<TKey, TValue> $config
     */
    public function __construct(
        private array $config, 
        protected ?ResourceInterface $resource = null)
    {
    }

    /**
     * @return array<TKey, TValue>
     */
    public function __invoke()
    {
        return $this->config;
    }

    public function getResource(): ResourceInterface
    {
        return $this->resource;
    }
}
