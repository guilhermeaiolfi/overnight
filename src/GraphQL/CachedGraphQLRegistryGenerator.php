<?php

declare(strict_types=1);

namespace ON\GraphQL;

use GraphQL\Type\Schema;
use ON\GraphQL\Resolver\NestedCreateRelations;
use WeakMap;

class CachedGraphQLRegistryGenerator extends GraphQLRegistryGenerator
{
	private ?Schema $cachedSchema = null;
	private ?string $registryHash = null;
	/** @var WeakMap<object, int>|null */
	private ?WeakMap $overrideIds = null;
	private int $nextOverrideId = 0;

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
			$usesBuiltInNestedCreate = $this->usesBuiltInNestedCreate($collection);
			$parts[] = $usesBuiltInNestedCreate ? 'default-create' : 'custom-create';

			foreach ($collection->fields as $name => $field) {
				$enumMetadata = $field->metadata('enum');
				$enumPart = is_array($enumMetadata) ? json_encode($enumMetadata) : '';
				$overrideType = $this->typeOverrideFingerprint($field->metadata('gql::type'));

				$parts[] = [
					$name,
					$field->getColumn(),
					$field->getType(),
					$field->isPrimaryKey() ? 'pk' : 'npk',
					$field->isRequired() ? 'r' : 'o',
					$field->isNullable() ? 'n' : 'nn',
					$field->isHidden() ? 'h' : 'v',
					$field->isFilterable() ? 'f' : 'nf',
					$enumPart,
					$overrideType,
				];
			}

			foreach ($collection->relations as $name => $relation) {
				$parts[] = [
					'rel',
					$name,
					$relation->getCollectionName(),
					$relation::class,
					! $usesBuiltInNestedCreate || NestedCreateRelations::supports($relation) ? 'create' : 'read-only',
					$relation->getCardinality()->name,
					$relation->isJunction() ? 'j' : 'nj',
				];
			}
		}

		return hash('sha256', serialize($parts));
	}

	private function typeOverrideFingerprint(mixed $override): mixed
	{
		if (is_object($override)) {
			$this->overrideIds ??= new WeakMap();

			return ['object', $this->overrideIds[$override] ??= ++$this->nextOverrideId];
		}

		if (is_array($override)) {
			return array_map($this->typeOverrideFingerprint(...), $override);
		}

		return $override;
	}
}
