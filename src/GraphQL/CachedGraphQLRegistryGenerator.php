<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Schema;

class CachedGraphQLRegistryGenerator extends GraphQLRegistryGenerator
{
	private ?Schema $cachedSchema = null;
	private ?string $registryHash = null;

	public function generate(): Schema
	{
		$currentHash = $this->computeRegistryHash();

		if ($this->cachedSchema !== null && $this->registryHash === $currentHash) {
			return $this->cachedSchema;
		}

		$this->cachedSchema = parent::generate();
		$this->registryHash = $currentHash;

		return $this->cachedSchema;
	}

	/**
	 * Invalidate the cached schema, forcing regeneration on next generate() call.
	 */
	public function invalidate(): void
	{
		$this->cachedSchema = null;
		$this->registryHash = null;
	}

	/**
	 * Check if a cached schema is available.
	 */
	public function isCached(): bool
	{
		return $this->cachedSchema !== null;
	}

	/**
	 * Compute a hash of the current registry state to detect changes.
	 */
	protected function computeRegistryHash(): string
	{
		$parts = [];
		foreach ($this->ormRegistry->getCollections() as $collection) {
			$parts[] = $collection->getName();
			$parts[] = $collection->isHidden() ? '1' : '0';

			foreach ($collection->fields as $name => $field) {
				$parts[] = $name . ':' . $field->getType() . ':' . ($field->isRequired() ? 'r' : 'o');
			}

			foreach ($collection->relations as $name => $relation) {
				$parts[] = 'rel:' . $name . ':' . $relation->getCollection();
			}
		}

		return md5(implode('|', $parts));
	}
}
