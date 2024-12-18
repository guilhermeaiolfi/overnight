<?php

declare(strict_types=1);

namespace ON\CMS\Parser;

use Exception;
use ON\CMS\Definition\Registry;
use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\ShallowRelationNode;

class Parser
{
	public array $operators = [
		'_eq' => '=',
		'_neq' => '<>',
		'_lt' => '<',
		'_lte' => '<=',
		'_gt' => '>',
		'_gte' => '>=',
		'_in' => 'IN',
		'_nin' => 'NOT IN',
		'_null' => 'IS NULL',
		'_nnull' => 'IS NOT NULL',
		'_contains' => 'LIKE BINARY',
		'_icontains' => 'LIKE',
		'_ncontains' => 'NOT LIKE',
		'_starts_with' => 'LIKE BINARY',
		'_istarts_with' => 'LIKE',
		'_nstarts_with' => 'NOT LIKE BINARY',
		'_nistarts_with' => 'NOT LIKE',
		'_ends_with' => 'LIKE BINARY',
		'_iends_with' => 'LIKE BINARY',
		'_nends_with' => 'NOT LIKE BINARY',
		'_niends_with' => 'NOT LIKE',
		'_between' => 'BETWEEN',
		'_nbetween' => 'NOT BETWEEN',
		'_empty' => '=',
		'_nempty' => '<>',
	];

	protected function _empty(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				'=' => '',
			],
		];
	}

	protected function _operator(string $column, string $operator, mixed $value): array
	{
		return [
			$column => [
				$this->operators[$operator] => $value,
			],
		];
	}

	public function flatten($delimiter = '.', $items = null, $prepend = '')
	{
		$flatten = [];

		foreach ($items as $key => $value) {
			if (is_array($value) && ! empty($value) && $key[0] != "_") {
				$flatten[] = $this->flatten($delimiter, $value, $prepend . $key . $delimiter);
			} else {
				$method = $key;
				if (! method_exists($this, $method)) {
					$method = "_operator";
				}
				$column = substr($prepend, 0, -1);
				$flatten[] = $this->$method($column, $key, $value);
			}
		}

		return array_merge(...$flatten);
	}

	/**
	 * {
	 *   "author": {
	 *      "_id": {
	 *          "_eq": 1
	 *      }
	 *		"vip": { // <-- relation
	 *			"_eq": true
	 *   	}
	 *   }
	 * }
	 */
	public function normalizeWhere($array): array
	{
		$array = $this->flatten(".", $array);
		dd($array);
		$where = [];
		$column = [];
		$nodes = [$array];
		while ($current = array_pop($nodes)) {
			foreach ($current as $key => $arr) {
				if (is_string($key)) {
					if ($key[0] == "_") {
						// operator
						$method = $key;
						if (! method_exists($this, $method)) {
							$method = "_operator";
						}
						[$columnName, $values] = $this->$method($column, $key, $arr);
						$where[$columnName] = $values;
					} else {
						$column[] = $key;
						$nodes[] = $array[$key];
					}
				}
			}
			array_pop($column);
		}

		return $where;
	}

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

	public function createNode(string $token, Registry $registry, RelationNode|RootNode $currentNode = null, bool $shallow = false): Node
	{
		if (! isset($currentNode)) {
			$collection = $registry->getCollection($token);
			if (! isset($collection)) {
				throw new Exception("Invalid root collection to search: " . $token);
			}

			return new RootNode($token);
		}

		$collection = $registry->getCollection($currentNode->collection);

		if ($token == "parts" && $currentNode->collection == "users") {
			//var_dump(array_keys($collection->relations), $collection->relations->has($token));
			//exit;
		}
		if ($collection->relations->has($token)) {
			$relation = $collection->relations->get($token);
			$collection = $registry->getCollection($currentNode->collection);
			$relationCollection = $registry->getCollection($relation->getCollection());
			$modifier = $this->getModifier($token);
			$node = null;
			if ($modifier) {
				$token = substr($token, 1);
			}
			if ($shallow) {
				$node = new ShallowRelationNode($token, $currentNode, $relationCollection->getName(), $modifier);
			} else {
				$node = new RelationNode($token, $currentNode, $relationCollection->getName(), $modifier);
			}
		} elseif ($collection->fields->has($token)) {
			$node = new FieldNode($token, $currentNode);
		} else {
			throw new Exception("There is no field {$token} in collection " . $collection->getName());
		}

		return $node;
	}

	public function parse(string $query, Registry $registry): RootNode
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
					$node = $this->createNode($token, $registry, $currentNode);
					$currentNode->addNode($node);
					while ($currentNode instanceof ShallowRelationNode) {
						$currentNode = $currentNode->parent;
					}
				} else {
					$node = $this->createNode($token, $registry, $currentNode);
					$currentNode->addNode($node);
				}
				$token = "";
			} elseif ($char == "{") {

				if (empty($token)) {
					throw new Exception("Invalid sintax");
				}

				$node = $this->createNode($token, $registry, $currentNode);
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
						$this->createNode($token, $registry, $currentNode)
					);
				}
				$currentNode = $currentNode->parent;
				while ($currentNode instanceof ShallowRelationNode) {
					$currentNode = $currentNode->parent;
				}
				$token = "";

			} elseif ($char == ".") {
				$node = $this->createNode($token, $registry, $currentNode, true);
				$currentNode->addNode($node);

				$currentNode = $node;
				$token = "";

			} elseif (($currentPos + 1) == strlen($query)) {
				$token .= $char;
				$currentNode->addNode(
					$this->createNode($token, $registry, $currentNode)
				);
			} else {
				$token .= $char;
			}
		}

		return $rootNode;
	}
}
