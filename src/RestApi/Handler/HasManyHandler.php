<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Resolver\Sql\SqlDataSource;

class HasManyHandler extends HasOneHandler
{
	public function configureParserNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->getSelectColumns(),
			$this->getPrimaryKeyColumns($this->getTargetCollection()),
			array_map(
				fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
				$this->relation->outerKeys()
			),
			array_map(
				fn(string $fieldName): string => $this->getCollection()->fields->get($fieldName)->getColumn(),
				$this->relation->innerKeys()
			)
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function normalizePayload(
		string $operation,
		mixed $input,
		MutationStateInterface $source,
		SqlDataSource $dataSource
	): array {
		$payload = $this->getEmptyMutationPayload();
		$targetCollection = $this->getTargetCollection();
		if (!is_array($input)) {
			return $payload;
		}

		if ($this->isDetailedPayload($input)) {
			return $this->normalizeDetailedHasRelationPayload($input, $source);
		}

		$currentRows = $operation === 'create' ? [] : $this->getCurrentRelationRows($dataSource, $source);
		$currentById = [];
		foreach ($currentRows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($targetCollection, $row);
			if ($id !== null) {
				$currentById[$id->toUrlId()] = $row;
			}
		}

		$seen = [];
		foreach ($this->normalizeRelationItems($input) as $item) {
			if (!is_array($item)) {
				$payload['connect'][] = $item;
				$seen[(string) $item] = true;
				continue;
			}

			$id = $this->getInputPrimaryKeyValue($targetCollection, $item);
			if ($id === null) {
				$this->applySourceValuesToTargetInput($item, $source);
				$payload['create'][] = $item;
				continue;
			}

			$key = $id->toUrlId();
			$seen[$key] = true;
			$this->applySourceValuesToTargetInput($item, $source);
			if (isset($currentById[$key])) {
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
