<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Relation\RelationInterface;

final class RelationLoad
{
	/** @var array{select: array, requested: array, internal: array} */
	private array $columns;

	private ?RelationLoaderInterface $loader = null;

	private ?AbstractNode $node = null;

	/** @var list<self> */
	private array $children = [];

	/**
	 * @param array{fields?: array, relations?: array, _relation?: string} $fieldTree
	 */
	public function __construct(
		public readonly CollectionInterface $sourceCollection,
		public readonly CollectionInterface $targetCollection,
		public readonly RelationInterface $relation,
		public readonly string $responseName,
		public readonly array $fieldTree,
		public readonly array $deep,
		public readonly QueryContext $context
	) {
		$this->columns = $this->buildColumns();
	}

	public static function create(
		CollectionInterface $source,
		string $relationName,
		array $relationData,
		array $deep,
		QueryContext $context
	): ?self {
		$targetRelationName = $relationData['_relation'] ?? $relationName;
		if (!$source->relations->has($targetRelationName)) {
			return null;
		}

		$relation = $source->relations->get($targetRelationName);
		$target = $context->registry->getCollection($relation->getCollection());
		if ($target === null) {
			return null;
		}

		return new self(
			$source,
			$target,
			$relation,
			$relationName,
			$relationData,
			is_array($deep) ? $deep : [],
			$context
		);
	}

	public function configure(AbstractNode $parent, LoaderFactory $factory): void
	{
		$this->loader = $factory->relation($this);
		$this->node = $this->loader->configureNode($parent, $this->responseName);
	}

	public function prepare(): void
	{
		$this->loader()->prepare($this->context);
	}

	public function load(): void
	{
		$this->loader()->load($this->node());
	}

	public function node(): AbstractNode
	{
		if ($this->node === null) {
			throw new \LogicException('Relation load has not been configured.');
		}

		return $this->node;
	}

	/**
	 * @param list<self> $children
	 */
	public function setChildren(array $children): void
	{
		$this->children = $children;
	}

	/**
	 * @return list<self>
	 */
	public function children(): array
	{
		return $this->children;
	}

	public function isSingle(): bool
	{
		return $this->relation->getCardinality() === 'single';
	}

	public function getSelectColumns(): array
	{
		return $this->columns['select'];
	}

	public function getNestedRelations(?string $relationName = null): ?array
	{
		$relations = $this->fieldTree['relations'] ?? [];
		if ($relationName === null) {
			return $relations;
		}

		$relation = $relations[$relationName] ?? null;

		return is_array($relation) ? $relation : null;
	}

	public function getRequestedColumns(): array
	{
		return $this->columns['requested'];
	}

	public function getInternalColumns(): array
	{
		return $this->columns['internal'];
	}

	public function filters(): array
	{
		return !empty($this->deep['_filter']) && is_array($this->deep['_filter'])
			? $this->deep['_filter']
			: [];
	}

	public function orderBy(): array
	{
		if (empty($this->deep['_sort'])) {
			return [];
		}

		$orders = [];
		foreach (array_map('trim', explode(',', (string) $this->deep['_sort'])) as $part) {
			if ($part === '') {
				continue;
			}

			$direction = 'ASC';
			if (str_starts_with($part, '-')) {
				$direction = 'DESC';
				$part = substr($part, 1);
			}

			$expression = $this->context->expressions->value($this->targetCollection, $part, $this->targetCollection->getTable());
			if ($expression !== null) {
				$orders[] = [
					'expression' => $expression,
					'direction' => $direction,
				];
			}
		}

		return $orders;
	}

	public function limit(): ?int
	{
		return isset($this->deep['_limit']) ? (int) $this->deep['_limit'] : null;
	}

	public function offset(): ?int
	{
		return isset($this->deep['_offset']) ? (int) $this->deep['_offset'] : null;
	}

	/**
	 * @return array{select: array, requested: array, internal: array}
	 */
	private function buildColumns(): array
	{
		$fieldNames = $this->fieldTree['fields'] ?? [];
		$requestedFieldNames = $this->fieldTree['requestedFields'] ?? $fieldNames;
		$requiredKey = (string) $this->relation->getOuterKey();

		if ($fieldNames !== []) {
			$selected = [];
			foreach ($fieldNames as $fieldName) {
				if ($this->targetCollection->fields->has($fieldName)) {
					$selected[] = $this->targetCollection->fields->get($fieldName)->getColumn();
				}
			}

			$requested = [];
			foreach ($requestedFieldNames as $fieldName) {
				if ($this->targetCollection->fields->has($fieldName)) {
					$requested[] = $this->targetCollection->fields->get($fieldName)->getColumn();
				}
			}

			$internal = [$requiredKey];
			foreach ($this->relationKeyColumnNames($this->targetCollection, $this->getNestedRelations()) as $nestedKey) {
				if (!in_array($nestedKey, $internal, true)) {
					$internal[] = $nestedKey;
				}
			}
			foreach ($internal as $column) {
				if (!in_array($column, $selected, true)) {
					$selected[] = $column;
				}
			}

			return [
				'select' => array_values(array_unique($selected)),
				'requested' => array_values(array_unique($requested)),
				'internal' => array_values(array_unique($internal)),
			];
		}

		$visible = [];
		foreach ($this->targetCollection->fields as $field) {
			if (!$field->isHidden()) {
				$visible[] = $field->getColumn();
			}
		}

		$selected = $visible;
		if (!in_array($requiredKey, $selected, true)) {
			$selected[] = $requiredKey;
		}

		return [
			'select' => array_values(array_unique($selected)),
			'requested' => $visible,
			'internal' => [$requiredKey],
		];
	}

	private function relationKeyColumnNames(CollectionInterface $collection, array $relations): array
	{
		$columns = [];
		foreach ($relations as $name => $data) {
			$targetName = is_array($data) ? ($data['_relation'] ?? $name) : $name;
			if ($collection->relations->has($targetName)) {
				$columns[] = (string) $collection->relations->get($targetName)->getInnerKey();
			}
		}

		return array_values(array_unique($columns));
	}

	private function loader(): RelationLoaderInterface
	{
		if ($this->loader === null) {
			throw new \LogicException('Relation load has not been configured.');
		}

		return $this->loader;
	}
}
