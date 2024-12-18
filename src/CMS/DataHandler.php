<?php

declare(strict_types=1);

namespace ON\CMS;

use Cycle\ORM\Select;
use Exception;
use ON\CMS\Compiler\CycleCompiler;
use ON\CMS\Definition\Registry;
use ON\CMS\Parser\FilterParser;
use ON\CMS\Parser\Node\Node;
use ON\CMS\Parser\Node\RelationNode;
use ON\CMS\Parser\Node\ShallowRelationNode;
use ON\CMS\Parser\Normalizer\MergeRelationsNormalizer;
use ON\CMS\Parser\Normalizer\UpdateRelationNormalizer;
use ON\CMS\Parser\Normalizer\VerifyNamesNormalizer;
use ON\CMS\Parser\Parser;
use ON\DB\DatabaseConfig;
use ON\DB\Manager;

class DataHandler
{
	protected Registry $registry;

	protected $modifiers = [
		MergeRelationsNormalizer::class,
		UpdateRelationNormalizer::class,
		VerifyNamesNormalizer::class,
	];

	protected array $modifierInstances = [];

	protected FilterParser $filterParser;

	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected Manager $db
	) {
		$this->registry = $dbCfg->getRegistry();

		foreach ($this->modifiers as $modifierClass) {
			$this->modifierInstances[] = new $modifierClass($this->registry);
		}

		$this->filterParser = new FilterParser();
	}

	public function parseFields(string $fields): Node
	{
		$parser = new Parser();

		return $parser->parse($fields, $this->registry);
	}

	public function postProcessFields($root): void
	{
		foreach ($this->modifierInstances as $modifier) {
			$modifier->execute($root);
		}
	}

	public function getSelectQuery(string $collection, array $queryParams): Select
	{
		$collection = $this->registry->getCollection($collection);
		$orm = $this->db->getDatabaseResource('cycle');

		$compiler = new CycleCompiler($this->registry);
		$compiler->compile();
		$schema = $orm->getSchema();

		$repository = $orm->getRepository($collection->getName());

		$select = $repository->select();
		$builder = $select->getBuilder();
		$loader = $builder->getLoader();

		$filter = json_decode($queryParams["filter"], true);

		if (! isset($queryParams["filter"]) && ! $filter) {
			throw new Exception("Invalid filter sintax");
		}


		$where = $this->filterParser->parse($filter);

		$select->where($where);
		//dd($loader->getTarget());



		$query = $collection->getName() . "{" . $queryParams["fields"] . "}";
		$root = $this->parseFields($query);
		$this->postProcessFields($root);

		//var_dump($fields);
		$relations = $this->getLoadRelations($root);

		$select->load($relations);

		$returnQuery = $loader->getQuery();

		$returnQuery->columns("id", "name");

		dd($select->fetchData());

		$filter = $this->parseFilter($queryParams["filter"]);

		dd($filter);
		//$select

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
