<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Normalizer;

use ON\CMS\Definition\Registry;
use ON\CMS\Parser\Node\ShallowRelationNode;

class MergeRelationsNormalizer
{
	protected $a = false;

	public function __construct(
		protected Registry $registry
	) {

	}

	public function execute($root, $node = null): void
	{
		if (! isset($node)) {
			$node = $root;
		}
		$this->executeNode($root, $node);
		foreach ($node->children as $child) {
			$this->execute($root, $child, $node);
		}
	}

	public function executeNode($root, $node)
	{
		$found = $this->findSameRelationNodes($node);

		if (count($found) < 1) {
			return;
		}

		foreach ($found as $item) {
			$from = $item;
			$to = $node;
			if ($node instanceof ShallowRelationNode) {
				$from = $node;
				$to = $item;
			}
			$this->merge($from, $to);
			$node->parent->removeNode($from);
		}

	}

	public function merge($from, $to)
	{
		//echo "merging from {$from->name} to {$to->name}\n\r";
		foreach ($from->children as $child) {
			if (! $to->hasNode($child->name)) {
				$to->addNode($child);
				$child->parent = $to;
			}
		}
	}

	public function findSameRelationNodes($node): array
	{
		$found = [];
		if (empty($node->parent->children)) {
			return $found;
		}
		foreach ($node->parent->children as $child) {
			if ($child->name == $node->name && $child != $node) {
				$found[] = $child;
			}
		}

		return $found;
	}
}
