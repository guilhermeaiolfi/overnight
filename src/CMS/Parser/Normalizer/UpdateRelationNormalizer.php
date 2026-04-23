<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Normalizer;

use Cycle\ORM\Select\AbstractLoader;
use ON\CMS\Parser\Node\RelationNode;
use ON\ORM\Definition\Registry;

class UpdateRelationNormalizer
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
			$this->execute($root, $child);
		}
	}

	public function executeNode($root, $node)
	{
		if ($node instanceof RelationNode) {
			if (isset($node->modifier)) {
				$node->method = $this->getLoadMethod($node->modifier);
			}
		}
	}

	/**
	 * % == 1 == SINGLE_QUERY == INLOAD
	 * : == 2 == OUTER_QUERY == POSTLOAD
	 * ! == 3 == JOIN
	 * ~ == 4 == LEFT_JOIN
	 */
	public function getLoadMethod(string $type): int
	{
		switch ($type) {
			case '%':
				return AbstractLoader::INLOAD;
			case ':':
				return AbstractLoader::POSTLOAD;
			case "!":
				return AbstractLoader::JOIN;
			case "~":
				return AbstractLoader::LEFT_JOIN;
		}

		return AbstractLoader::POSTLOAD;
	}
}
