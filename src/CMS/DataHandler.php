<?php

declare(strict_types=1);

namespace ON\CMS;

use Cycle\ORM\Select;
use Exception;
use ON\CMS\Definition\Collection\CollectionInterface;
use ON\CMS\Definition\Registry;
use ON\CMS\Definition\Relation\RelationInterface;
use ON\CMS\Parser\Node\Node;
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

	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected Manager $db
	) {
		$this->registry = $dbCfg->getRegistry();

		foreach ($this->modifiers as $modifierClass) {
			$this->modifierInstances[] = new $modifierClass($this->registry);
		}
	}

	public function parseFields(mixed $fields, ?string $rootCollection = null): Node
	{
		$parser = new Parser();

		return $parser->parse($fields, $rootCollection);
	}

	public function postProcessFields($root): void
	{
		foreach ($this->modifierInstances as $modifier) {
			$modifier->execute($root);
		}
	}

	public function getSelectQuery(string $collection, array $query): Select
	{
		$collection = $this->registry->getCollection($collection);
		$orm = $this->db->getDatabaseResource('cycle');
		$repository = $orm->getRepository($collection->getName());

		$select = $repository->select();
		$builder = $select->getBuilder();
		$loader = $builder->getLoader();
		//dd($loader->getTarget());

		$root = $this->parseFields($query["fields"], $collection->getName());
		$this->postProcessFields($root);

		dd($root);

		//var_dump($fields);
		$relations = $this->getLoadRelations($fields, $collection);
		$select->load($relations);

		return $select;
	}

	public function getLoadRelations(array $fields, CollectionInterface $collection): array
	{
		$load = [];
		foreach ($fields as $field => $options) {
			if (strpos($field, ".") !== false) {
				$field = explode(".", $field);
			}
			if (is_string($field)) {
				if ($collection->relations->has($field)) {
					$load[$field] = $options;
				}
			} else {
				$current = $collection;

				for ($i = 0; $i < count($field); $i++) {
					$last = count($field) == ($i + 1);
					if (! $current->relations->has($field[$i])) {
						throw new Exception("This relation is invalid to load: " . implode(".", $field) . "($i)");
					}

					$relation = $current->relations->get($field[$i]);
					/** @var RelationInterface $relation */
					$current = $this->registry->getCollection($relation->getCollection());
					if ($last) {
						$load[implode(".", $field)] = $options;
					}
				}
			}
		}

		return $load;
	}
}
