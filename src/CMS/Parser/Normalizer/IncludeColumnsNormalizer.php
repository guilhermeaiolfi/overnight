<?php

declare(strict_types=1);

namespace ON\CMS\Parser\Normalizer;

use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\RelationNode;
use ON\ORM\Definition\Registry;

/**
 * For example in the query into the 'book' collection: { id, name, chapters: { id }}
 * 'Chapters' being a HasOneRelation, like so, have an 'book_id' innerKey that links it
 * to 'book' collection. Even though it is not there, we should include the 'book_id'
 * field in the query, because it's necessary to the SQL query.
 */
class IncludeColumnsNormalizer
{
	public function __construct(
		protected Registry $registry
	) {

	}

	public function execute($root)
	{
		$this->executeNode($root, $root);
	}

	public function executeNode($root, $node)
	{
		// execute code for node
		if ($root != $node) {
			if ($node instanceof RelationNode) {
				$node_collection = $this->registry->getCollection($node->collection);

				// see if needs fields to be included
				foreach ($node_collection->fields as $field) {
					if ($field->getGeneratedFromRelation() != null) {
						$fieldNode = new FieldNode($field->getName(), $node);
						$node->addNode($fieldNode);
					}
				}
			}
		}

		// go deeper into node hierarchy
		foreach ($node->children as $child) {
			$this->executeNode($root, $child);
		}
	}
}
