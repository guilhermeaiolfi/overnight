<?php

declare(strict_types=1);

namespace ON\CMS\Parser;

use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\ShallowRelationNode;

class Parser
{
	public function hasModifier($token): bool
	{
		return in_array($token[0], ["%", "~", ":", "!"]);
	}

	public function getModifier($token): ?string
	{
		if ($this->hasModifier($token)) {
			return $token[0];
		}

		return null;
	}

	public function getNodeClass($token): string
	{
		if ($this->hasModifier($token)) {
			return RelationNode::class;
		}

		return Node::class;
	}

	public function createNode($token, $currentNode, $class = Node::class): Node
	{
		$modifier = $this->getModifier($token);
		$node = null;
		if ($modifier || $class == RelationNode::class) {
			if ($modifier) {
				$token = substr($token, 1);
			}
			$node = new RelationNode($token, $currentNode, $modifier);
		} else {
			$node = new Node($token, $currentNode);
		}

		return $node;
	}

	public function parse(string $code, string $rootCollection = "root"): Node
	{
		$currentPos = 0;
		$currentNode = $rootNode = new Node($rootCollection);
		$token = "";
		for ($currentPos; $currentPos < strlen($code); $currentPos++) {
			$char = $code[$currentPos];
			if ($char == " ") { //ignore spaces
				continue;
			} elseif ($char == ",") {
				if (empty($token)) {
					continue;
				}

				if ($currentNode instanceof ShallowRelationNode) {
					$currentNode->addNode($this->createNode($token, $currentNode, RelationNode::class));
					while ($currentNode instanceof ShallowRelationNode) {
						$currentNode = $currentNode->parent;
					}
				} else {
					$currentNode->addNode($this->createNode($token, $currentNode));
				}
				$token = "";
			} elseif ($char == "{") {

				$node = $this->createNode($token, $currentNode, RelationNode::class);
				$currentNode->addNode($node);
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
				$node = new ShallowRelationNode($token, $currentNode);
				$currentNode->addNode($node);

				$currentNode = $node;
				$token = "";

			} elseif (($currentPos + 1) == strlen($code)) {
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
