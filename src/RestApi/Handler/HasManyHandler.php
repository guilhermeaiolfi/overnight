<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\RestApi\Handler\Mutation\ForeignKeyOnTargetApply;

class HasManyHandler extends AbstractRelationHandler implements RelationMutationHandlerInterface
{
	use ForeignKeyOnTargetApply;

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

	public function load(): mixed
	{
		$node = $this->getNode();
		$parentKeySets = $this->getReferenceValueSets($node);
		if ($parentKeySets === []) {
			return null;
		}

		$columns = $this->getSelectColumns();
		$query = $this->baseQuery($columns);
		$outerKeyColumns = array_map(
			fn(string $fieldName): string => $this->getTargetCollection()->fields->get($fieldName)->getColumn(),
			$this->relation->outerKeys()
		);
		if (count($outerKeyColumns) === 1) {
			$query->where($outerKeyColumns[0], 'IN', array_map(
				static fn(array $set): mixed => reset($set),
				$parentKeySets
			));
		} else {
			$query->where(function ($nested) use ($parentKeySets, $outerKeyColumns) {
				foreach ($parentKeySets as $set) {
					$nested->orWhere(array_combine($outerKeyColumns, array_values($set)));
				}
			});
		}
		$this->applyRelationQueryOptions($query);

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubquery(
				$query,
				$columns,
				$this->getTargetCollection()->getTable() . '.' . $outerKeyColumns[0]
			);
		}

		$this->parseLoadedRows($node, $query);

		return null;
	}
}
