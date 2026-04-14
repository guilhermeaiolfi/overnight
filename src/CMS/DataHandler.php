<?php

declare(strict_types=1);

namespace ON\CMS;

use Exception;
use ON\CMS\Parser\FilterParser;
use ON\CMS\Parser\Node\FieldNode;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\ShallowRelationNode;
use ON\CMS\Parser\Normalizer\IncludeColumnsNormalizer;
use ON\CMS\Parser\Normalizer\MergeRelationsNormalizer;
use ON\CMS\Parser\Normalizer\UpdateRelationNormalizer;
use ON\CMS\Parser\Normalizer\VerifyNamesNormalizer;
use ON\CMS\Parser\QueryParser;
use ON\DB\DatabaseConfig;
use ON\DB\DatabaseManager;
use ON\ORM\Definition\Registry as DefinitionRegistry;
use ON\ORM\Factory;
use ON\ORM\Select;

class DataHandler
{
	protected DefinitionRegistry $registry;

	protected $modifiers = [
		MergeRelationsNormalizer::class,
		UpdateRelationNormalizer::class,
		VerifyNamesNormalizer::class,
		//IncludeColumnsNormalizer::class,
	];

	protected array $modifierInstances = [];

	protected FilterParser $filterParser;
	protected QueryParser $queryParser;

	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected DatabaseManager $db
	) {
		$this->registry = $dbCfg->getRegistry();

		foreach ($this->modifiers as $modifierClass) {
			$this->modifierInstances[] = new $modifierClass($this->registry);
		}

		$this->filterParser = new FilterParser($this->registry);
		$this->queryParser = new QueryParser($this->registry);
	}

	public function postProcessFields($root): void
	{
		foreach ($this->modifierInstances as $modifier) {
			$modifier->execute($root);
		}
	}

	public function parseQuery(string $query): RootNode
	{
		$rootNode = $this->queryParser->parse($query);
		$this->postProcessFields($rootNode);

		return $rootNode;
	}

	public function getSelectQuery(string $collection, array $queryParams): Select
	{
		$collection = $this->registry->getCollection($collection);

		$factory = new Factory($this->db->getDatabaseConnection('cycle'));
		$orm = $this->db->getDatabaseResource('cycle');

		$select = new Select($orm, $this->registry, $factory, $collection); //$repository->select();

		// filter handling
		$filter = json_decode($queryParams["filter"], true);

		if (isset($queryParams["filter"]) && ! $filter) {
			throw new Exception("Invalid filter sintax");
		}
		$where = $this->filterParser->parse($filter);
		$select->where($where);

		$query = $collection->getName() . "{" . $queryParams["fields"] . "}";
		$rootNode = $this->parseQuery($query);

		// select columns for the main collection
		$select->columns($this->getColumnsFilter($rootNode));

		// relations loading, including the columns to load in each relation
		$relations = $this->getLoadRelations($rootNode);
		$select->load($relations);

		// limit handling
		$limit = 100;
		if (isset($queryParams["limit"]) && $queryParams["limit"] > 0) {
			$limit = (int) $queryParams["limit"];
		}

		$select->limit($limit);


		// offset handling
		$offset = 0;
		if (isset($queryParams["page"]) && $queryParams["page"] > 0) {
			$page = (int) $queryParams["page"];
			$offset = ($page - 1) * $limit;
		} elseif (isset($queryParams["offset"]) && is_int($queryParams["offset"])) {
			$offset = $queryParams["offset"];
		}
		$select->offset($offset);

		return $select;
	}

	public function parseFilter($filter): array
	{
		$arr = json_decode($filter, true);

		return $arr;
	}

	public function getAllRelations(Node $root): array
	{
		$relations = [];

		$stack = [ $root ];
		while ($node = array_pop($stack)) {
			if ($node instanceof RelationNode) {
				$relations[] = $node;
			}
			foreach ($node->children as $child) {
				$stack[] = $child;
			}
		}

		return $relations;
	}

	public function getColumnsFilter(Node $node): array
	{
		$fields = $node->getChildren(FieldNode::class);

		$columns = [];
		foreach ($fields as $i => $field) {
			if ($field->name == "*") {
				return ["*"];
			}
			$columns[$field->name] = true;
		}

		return $columns;
	}

	public function getLoadOptions(RelationNode $node): array
	{

		$options = [];
		if (isset($node->method)) {
			$options["method"] = $node->method;
		}
		$options["columns"] = $this->getColumnsFilter($node);

		return $options;
	}

	public function getLoadRelations(Node $root): array
	{
		$load = [];

		$relations = $this->getAllRelations($root);

		foreach ($relations as $relation) {
			if ($relation instanceof ShallowRelationNode) {
				continue;
			}
			$path = $relation->getPath(1);
			$keys = [];
			foreach ($path as $node) {
				$keys[] = $node->name;
			}
			$load[implode(".", $keys)] = $this->getLoadOptions($relation);
		}

		return $load;
	}
}
