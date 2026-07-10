<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use InvalidArgumentException;
use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\VirtualNode;
use ON\CMS\Parser\QueryParser;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class CmsQueryParser
{
	public function __construct(
		private readonly Registry $registry,
	) {
	}

	public function parseQuery(string $query): SelectQuery
	{
		$root = (new QueryParser($this->registry))->parse($query);
		if (! $root instanceof RootNode || $root->collection === null) {
			throw new InvalidArgumentException('CMS query did not produce a root collection.');
		}

		$collection = $this->registry->getCollection($root->collection);
		$select = new SelectQuery($collection);
		$this->applyChildren($select, $collection, $root->children);

		return $select;
	}

	/** @param list<Node> $children */
	private function applyChildren(
		SelectQuery $query,
		CollectionInterface $collection,
		array $children,
		?RelationRef $parent = null,
	): void {
		$fields = [];
		$wildcard = false;

		foreach ($children as $child) {
			if ($child instanceof VirtualNode || $child->name === '*') {
				$wildcard = true;
				continue;
			}

			if ($child instanceof RelationNode || $collection->relations->has($child->name)) {
				$relation = $parent === null
					? $query->relation($child->name)
					: $parent->relation($child->name);
				$this->applyStrategy($relation, $child->modifier);
				$this->applyChildren($query, $relation->getCollection(), $child->children, $relation);
				continue;
			}

			if ($child instanceof FieldNode || $collection->fields->has($child->name)) {
				$fields[] = $child->name;
				continue;
			}

			throw new InvalidArgumentException("Field or relation '{$child->name}' is not registered on {$collection->getName()}.");
		}

		if ($parent !== null) {
			if ($wildcard || $fields === []) {
				$parent->load();
			} else {
				$parent->fields(...$fields);
			}
			return;
		}

		if (! $wildcard && $fields !== []) {
			$query->select(...array_map(
				static fn (string $field) => $query->field($field),
				$fields,
			));
		}
	}

	private function applyStrategy(RelationRef $relation, ?string $modifier): void
	{
		$strategy = match ($modifier) {
			'!', '~' => LoadStrategy::JOIN,
			'%', ':' => LoadStrategy::SEPARATE_QUERY,
			default => null,
		};

		if ($strategy !== null) {
			$relation->strategy($strategy);
		}
	}
}
