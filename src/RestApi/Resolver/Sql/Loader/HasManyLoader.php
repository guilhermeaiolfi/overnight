<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\DataSourceInterface;

class HasManyLoader extends HasOneLoader
{
	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->getSelectColumns(),
			[$this->getPrimaryKeyColumn($this->getTargetCollection())],
			[$this->relation->getOuterField()->getColumn()],
			[$this->relation->getInnerField()->getColumn()]
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): void
	{
		$node = $this->getNode();
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$columns = $this->getSelectColumns();
		$query = $this->baseQuery($columns)
			->where($this->relation->getOuterField()->getColumn(), 'IN', $parentIds);
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . $this->relation->getOuterField()->getColumn()
			);
		}

		$this->parseLoadedRows($node, $query);
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		DataSourceInterface $dataSource
	): array {
		$payload = $this->emptyMutationPayload();
		$targetCollection = $this->getTargetCollection();
		$relationKey = $this->relation->getOuterField()->getName();
		$parentId = $source->getValue($this->relation->getInnerField()->getName());

		if (!is_array($input)) {
			return $payload;
		}

		if ($this->isAssociativeArray($input) && $this->hasOperationPayload($input)) {
			foreach (['create', 'update'] as $mutation) {
				foreach ($this->normalizeRelationItems($input[$mutation] ?? []) as $item) {
					if (!is_array($item)) {
						continue;
					}

					if ($mutation === 'create') {
						$item[$relationKey] = $parentId;
					}

					$payload[$mutation][] = $item;
				}
			}

			$payload['delete'] = $this->normalizeRelationItems($input['delete'] ?? []);
			$payload['connect'] = $this->normalizeRelationItems($input['connect'] ?? []);
			$payload['disconnect'] = $this->normalizeRelationItems($input['disconnect'] ?? []);

			return $payload;
		}

		$currentRows = $operation === 'create' ? [] : $this->currentRelationRows($dataSource, $source);
		$currentById = [];
		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->inputPrimaryKeyValue($targetCollection, $row);
			if ($id !== null) {
				$currentById[(string) $id] = $row;
			}
		}

		$seen = [];
		foreach ($this->normalizeRelationItems($input) as $item) {
			if (!is_array($item)) {
				$payload['connect'][] = $item;
				$seen[(string) $item] = true;
				continue;
			}

			$id = $this->inputPrimaryKeyValue($targetCollection, $item);
			if ($id === null) {
				$item[$relationKey] = $parentId;
				$payload['create'][] = $item;
				continue;
			}

			$seen[(string) $id] = true;
			$item[$relationKey] = $parentId;
			if (isset($currentById[(string) $id])) {
				$payload['update'][] = $item;
				continue;
			}

			$payload['connect'][] = $id;
			if (count($item) > 1) {
				$payload['update'][] = $item;
			}
		}

		foreach ($currentById as $id => $row) {
			if (!isset($seen[(string) $id])) {
				$this->normalizeOmittedChildren($payload, [$row]);
			}
		}

		return $payload;
	}
}
