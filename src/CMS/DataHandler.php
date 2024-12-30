<?php

declare(strict_types=1);

namespace ON\CMS;

use Exception;
use ON\CMS\Compiler\CycleCompiler;
use ON\CMS\Parser\FilterParser;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\RootNode;
use ON\CMS\Parser\Node\ShallowRelationNode;
use ON\CMS\Parser\Normalizer\MergeRelationsNormalizer;
use ON\CMS\Parser\Normalizer\UpdateRelationNormalizer;
use ON\CMS\Parser\Normalizer\VerifyNamesNormalizer;
use ON\CMS\Parser\QueryParser;
use ON\DB\DatabaseConfig;
use ON\DB\Manager;
use ON\ORM\Definition\Registry;
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
	];

	protected array $modifierInstances = [];

	protected FilterParser $filterParser;
	protected QueryParser $queryParser;

	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected Manager $db
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
		$orm = $this->db->getDatabaseResource('cycle');

		/*$compiler = new CycleCompiler($this->registry);
		$compiler->compile();
		$schema = $orm->getSchema();*/

		$repository = $orm->getRepository($collection->getName());

		$factory = new Factory($this->db->getDatabaseConnection('cycle'));
		$select = new Select($this->registry, $factory, $orm, $collection->getName()); //$repository->select();
		$builder = $select->getBuilder();
		$loader = $select->getLoader();


		// filter handling
		$filter = json_decode($queryParams["filter"], true);

		if (! isset($queryParams["filter"]) && ! $filter) {
			throw new Exception("Invalid filter sintax");
		}
		$where = $this->filterParser->parse($filter);
		//dd($where);
		$select->where($where);



		$query = $collection->getName() . "{" . $queryParams["fields"] . "}";
		$rootNode = $this->parseQuery($query);


		// relations loading
		$relations = $this->getLoadRelations($rootNode);
		$select->load($relations);
		$loader->columns(["id", "name"]);
		//dd($loader);

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


		//dd($select->fetchData());

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

	public function getLoadOptions(RelationNode $node): array
	{
		if (isset($node->method)) {
			return [
				"method" => $node->method,
			];
		}

		return [];
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
