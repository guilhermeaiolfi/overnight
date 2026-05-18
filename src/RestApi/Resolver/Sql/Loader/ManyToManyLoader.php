<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use ON\ORM\Definition\Relation\M2MRelation;

final class ManyToManyLoader extends AbstractRelationLoader
{
	private ?string $parentKeyAlias = null;
	private ?string $junctionAlias = null;
	private ?string $targetAlias = null;

	public function configureNode(AbstractNode $parent): AbstractNode
	{
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			[$this->getPrimaryKeyColumn($this->getTargetCollection())],
			[$this->parentKeyAlias()],
			[$this->relation->getInnerField()->getColumn()]
		);
		$parent->linkNode($this->getResponseName(), $node);
		$this->setNode($node);

		return $node;
	}

	public function load(): void
	{
		if (!$this->relation instanceof M2MRelation) {
			return;
		}

		$node = $this->getNode();
		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$through = $this->relation->through;
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKey = $through->getInnerField()->getColumn();
		$throughOuterKey = $through->getOuterField()->getColumn();
		$targetPkColumn = $this->getPrimaryKeyColumn($this->getTargetCollection());

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias, $throughInnerKey);
		$query = $this->context->database->select($selectColumns)
			->from($through->getCollection()->getTable() . ' AS ' . $junctionAlias)
			->innerJoin($this->getTargetCollection()->getTable(), $targetAlias)
			->on(
				$junctionAlias . '.' . $throughOuterKey,
				'=',
				$targetAlias . '.' . $targetPkColumn
			)
			->where($junctionAlias . '.' . $throughInnerKey, 'IN', $parentIds);

		if ($this->filters() !== null) {
			$this->context->filterApplier->applyNode(
				$query,
				$this->getTargetCollection(),
				$this->filters(),
				$targetAlias,
				$this->context->aliases
			);
		}

		foreach ($this->orderBy() as $order) {
			if (!is_array($order) || !isset($order['expression'])) {
				continue;
			}

			$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
		}

		if ($this->limit() !== null || $this->offset() !== null) {
			$query = $this->limitedSubqueryWithColumns(
				$query,
				$selectColumns,
				$this->resultNodeColumns(),
				$junctionAlias . '.' . $throughInnerKey
			);
		}

		$this->parseLoadedRows($node, $query);
	}

	private function resultNodeColumns(): array
	{
		$columns = $this->getSelectColumns();
		$alias = $this->parentKeyAlias();
		if (!in_array($alias, $columns, true)) {
			$columns[] = $alias;
		}

		return $columns;
	}

	private function selectColumns(string $targetAlias, string $junctionAlias, string $throughInnerKey): array
	{
		$columns = [];
		foreach ($this->getSelectColumns() as $column) {
			$columns[] = new Expression($targetAlias . '.' . $column);
		}
		$columns[] = new Fragment(
			$this->compile(new Expression($junctionAlias . '.' . $throughInnerKey))
			. ' AS '
			. $this->identifier($this->parentKeyAlias())
		);

		return $columns;
	}

	private function parentKeyAlias(): string
	{
		return $this->parentKeyAlias ??= $this->context->aliases->alias('__on_' . $this->getResponseName() . '_parent_key');
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->context->aliases->alias('__on_' . $this->getResponseName() . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->context->aliases->alias('__on_' . $this->getResponseName() . '_target');
	}
}
