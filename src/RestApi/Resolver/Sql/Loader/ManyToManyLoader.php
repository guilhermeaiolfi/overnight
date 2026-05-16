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

	public function configureNode(AbstractNode $parent, string $name): AbstractNode
	{
		$node = new ArrayNode(
			$this->resultNodeColumns(),
			[$this->getPrimaryKeyColumn($this->load->targetCollection)],
			[$this->parentKeyAlias()],
			[(string) $this->load->relation->getInnerKey()]
		);
		$parent->linkNode($name, $node);

		return $node;
	}

	public function load(AbstractNode $node): void
	{
		if (!$this->load->relation instanceof M2MRelation) {
			return;
		}

		$parentIds = $this->flattenedReferenceValues($node);
		if ($parentIds === []) {
			return;
		}

		$through = $this->load->relation->through;
		$junctionAlias = $this->junctionAlias();
		$targetAlias = $this->targetAlias();
		$throughInnerKey = (string) $through->getInnerKey();
		$throughOuterKey = (string) $through->getOuterKey();
		$targetPkColumn = $this->getPrimaryKeyColumn($this->load->targetCollection);

		$selectColumns = $this->selectColumns($targetAlias, $junctionAlias, $throughInnerKey);
		$query = $this->load->context->database->select($selectColumns)
			->from($through->getCollection() . ' AS ' . $junctionAlias)
			->innerJoin($this->load->targetCollection->getTable(), $targetAlias)
			->on(
				$junctionAlias . '.' . $throughOuterKey,
				'=',
				$targetAlias . '.' . $targetPkColumn
			)
			->where($junctionAlias . '.' . $throughInnerKey, 'IN', $parentIds);

		if ($this->load->filters() !== []) {
			$this->load->context->filterApplier->apply($query, $this->load->targetCollection, $this->load->filters(), $targetAlias);
		}

		foreach ($this->load->orderBy() as $order) {
			if (!is_array($order) || !isset($order['expression'])) {
				continue;
			}

			$query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
		}

		if ($this->load->limit() !== null || $this->load->offset() !== null) {
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
		$columns = $this->load->getSelectColumns();
		$alias = $this->parentKeyAlias();
		if (!in_array($alias, $columns, true)) {
			$columns[] = $alias;
		}

		return $columns;
	}

	private function selectColumns(string $targetAlias, string $junctionAlias, string $throughInnerKey): array
	{
		$columns = [];
		foreach ($this->load->getSelectColumns() as $column) {
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
		return $this->parentKeyAlias ??= $this->load->context->aliases->alias('__on_' . $this->load->responseName . '_parent_key');
	}

	private function junctionAlias(): string
	{
		return $this->junctionAlias ??= $this->load->context->aliases->alias('__on_' . $this->load->responseName . '_junction');
	}

	private function targetAlias(): string
	{
		return $this->targetAlias ??= $this->load->context->aliases->alias('__on_' . $this->load->responseName . '_target');
	}
}
