<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\DataSourceInterface;

class HasManyHandler extends HasOneHandler
{
	public function configureParserNode(AbstractNode $parent): AbstractNode
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

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $parentId, $relationKey);
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
