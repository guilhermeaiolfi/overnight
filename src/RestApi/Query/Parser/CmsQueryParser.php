<?php

declare(strict_types=1);

namespace ON\RestApi\Query\Parser;

use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\VirtualNode;
use ON\CMS\Parser\QueryParser;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Node\RelationLoadHint;
use ON\RestApi\Query\Node\RelationQuerySpec;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SelectionNode;
use ON\RestApi\Query\Node\SelectionSet;
use ON\RestApi\Query\Node\WildcardSelection;

final class CmsQueryParser
{
	public function __construct(
		private readonly Registry $registry,
	) {
	}

	public function parseQuery(string $query): QuerySpec
	{
		$root = (new QueryParser($this->registry))->parse($query);
		if (!$root instanceof RootNode || $root->collection === null) {
			throw new \InvalidArgumentException('CMS query did not produce a root collection.');
		}

		$collection = $this->registry->getCollection($root->collection);
		if ($collection === null) {
			throw new \InvalidArgumentException("Collection '{$root->collection}' is not registered.");
		}

		return new QuerySpec(
			$root->collection,
			$this->selectionFromChildren($collection, $root->children, true)
		);
	}

	/**
	 * @param list<\ON\CMS\Parser\Node\Node> $children
	 */
	private function selectionFromChildren(CollectionInterface $collection, array $children, bool $explicit): SelectionSet
	{
		$nodes = [];
		foreach ($children as $child) {
			$nodes[] = $this->selectionNode($collection, $child);
		}

		return new SelectionSet($nodes, $explicit);
	}

	private function selectionNode(CollectionInterface $collection, \ON\CMS\Parser\Node\Node $node): SelectionNode
	{
		if ($node instanceof VirtualNode || $node->name === '*') {
			return new WildcardSelection();
		}

		if ($node instanceof RelationNode || $collection->relations->has($node->name)) {
			$relation = $collection->relations->get($node->name);
			$targetCollection = $collection->getRegistry()->getCollection($relation->getCollection());
			if ($targetCollection === null) {
				throw new \InvalidArgumentException("Collection '{$relation->getCollection()}' is not registered.");
			}

			return new RelationSelection(
				$node->name,
				$node->name,
				$targetCollection->getName(),
				new RelationQuerySpec($this->selectionFromChildren($targetCollection, $node->children, true)),
				$this->loadHint($node->modifier)
			);
		}

		if ($node instanceof FieldNode || $collection->fields->has($node->name)) {
			return new FieldSelection(new FieldExpression($node->name), $node->name);
		}

		throw new \InvalidArgumentException("Field or relation '{$node->name}' is not registered on {$collection->getName()}.");
	}

	private function loadHint(?string $modifier): ?RelationLoadHint
	{
		return match ($modifier) {
			'%' => RelationLoadHint::InLoad,
			':' => RelationLoadHint::PostLoad,
			'!' => RelationLoadHint::Join,
			'~' => RelationLoadHint::LeftJoin,
			default => null,
		};
	}
}
