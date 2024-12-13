<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Normalizer;

use Exception;
use ON\CMS\Definition\Collection\CollectionInterface;
use ON\CMS\Definition\Registry;
use ON\CMS\Parser\Node\RelationNode;

class VerifyNamesNormalizer
{
	protected $a = false;

	public function __construct(
		protected Registry $registry
	) {

	}

	public function execute($root)
	{
		$this->executeNode($root, $root);
		/*foreach ($node->children as $child) {
			$this->execute($root, $child, $node);
		}*/
	}

	public function executeNode($root, $node, $collection = null)
	{
		if (! isset($collection)) {
			$collection = $this->registry->getCollection($root->name);
		}

		// execute code for node
		if ($root != $node) {

			$isRelation = $collection->relations->has($node->name);
			$isField = $collection->fields->has($node->name);
			if ($isRelation) {
				if (! ($node instanceof RelationNode)) {
					$this->changeToRelation($node);
				}
			} elseif (! $isField) {
				throw new Exception("Field {$node->name} is not present in any form in the collection: " . $collection->getName());
			}
		}

		// go deeper into node hierarchy
		foreach ($node->children as $child) {
			$this->executeNode($root, $child, $collection);
		}
	}

	public function changeToRelation($node)
	{
		$relation = new RelationNode($node->name);
		$relation->children = $node->children;
		$node->parent->addNode($relation);
		$node->parent->removeNode($node);
	}

	/*public function isRelation($node): CollectionInterface
	{
		$path = [];
		while ($node) {
			array_unshift($path, $node->name);
			$node = $node->parent;
		}

		$collection = $this->registry->getCollection($path[0]);
		unset($path[0]);
		foreach ($path as $relationName) {
			if ($collection->relations->has($relationName)) {
				$relation = $collection->relations->get($relationName);
				$collection = $this->registry->getCollection($relation->getCollection());
			} else {
				$collection = null;
			}
		}

		return $collection;
	}*/
}
