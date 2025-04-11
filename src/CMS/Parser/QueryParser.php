<?php

declare(strict_types=1);

namespace ON\CMS\Parser;

use Exception;
use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\ShallowRelationNode;
use ON\CMS\Parser\Node\VirtualNode;
use ON\ORM\Definition\Registry;

class QueryParser
{
	public function __construct(
		protected Registry $registry
	) {

	}

	public function hasModifier($token): bool
	{
		return in_array($token[0], ["+", "-", "%", "~", ":", "!"]);
	}

	public function getModifier($token): ?string
	{
		if ($this->hasModifier($token)) {
			return $token[0];
		}

		return null;
	}

	public function createNode(string $token, mixed $currentNode, bool $shallow = false): Node
	{
		if (! isset($currentNode)) {
			$collection = $this->registry->getCollection($token);
			if (! isset($collection)) {
				throw new Exception("Invalid root collection to search: " . $token);
			}

			return new RootNode($token);
		}

		$collection = $this->registry->getCollection($currentNode->collection);

		$modifier = $this->getModifier($token);
		if ($modifier) {
			$token = substr($token, 1);
		}

		if ($collection->relations->has($token)) {
			$relation = $collection->relations->get($token);
			$collection = $this->registry->getCollection($currentNode->collection);
			$relationCollection = $this->registry->getCollection($relation->getCollection());

			$node = null;

			if ($shallow) {
				$node = new ShallowRelationNode($token, $currentNode, $relationCollection->getName(), $modifier);
			} else {
				$node = new RelationNode($token, $currentNode, $relationCollection->getName(), $modifier);
			}
		} elseif ($collection->fields->has($token)) {
			$node = new FieldNode($token, $currentNode);
			if (isset($modifier)) {
				$node->modifier = $modifier;
			}
		} elseif ($token == "*") {
			$node = new VirtualNode($token, $currentNode);
		} else {
			throw new Exception("There is no field {$token} in collection " . $collection->getName());
		}

		return $node;
	}

	public function parse(string $query): RootNode
	{
		$currentPos = 0;
		$currentNode = $rootNode = null;
		$token = "";
		for ($currentPos; $currentPos < strlen($query); $currentPos++) {
			$char = $query[$currentPos];
			if ($char == " ") { //ignore spaces
				continue;
			} elseif ($char == ",") {
				if (empty($token)) {
					continue;
				}

				if ($currentNode instanceof ShallowRelationNode) {
					$node = $this->createNode($token, $currentNode);
					$currentNode->addNode($node);
					while ($currentNode instanceof ShallowRelationNode) {
						$currentNode = $currentNode->parent;
					}
				} else {
					$node = $this->createNode($token, $currentNode);
					$currentNode->addNode($node);
				}
				$token = "";
			} elseif ($char == "{") {

				if (empty($token)) {
					throw new Exception("Invalid sintax");
				}

				$node = $this->createNode($token, $currentNode);
				if (! isset($currentNode)) {
					$currentNode = $rootNode = $node;
				} else {
					$currentNode->addNode($node);
				}
				$currentNode = $node;
				$token = "";

			} elseif ($char == "}") {
				if (! empty($token)) {

					$currentNode->addNode(
						$this->createNode($token, $currentNode)
					);
				}
				$currentNode = $currentNode->parent;
				while ($currentNode instanceof ShallowRelationNode) {
					$currentNode = $currentNode->parent;
				}
				$token = "";

			} elseif ($char == ".") {
				$node = $this->createNode($token, $currentNode, true);
				$currentNode->addNode($node);

				$currentNode = $node;
				$token = "";

			} elseif (($currentPos + 1) == strlen($query)) {
				// end of string
				$token .= $char;
				$currentNode->addNode(
					$this->createNode($token, $currentNode)
				);
			} else {
				$token .= $char;
			}
		}

		return $rootNode;
	}
}
