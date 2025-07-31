<?php

declare(strict_types=1);

namespace ON\CMS\Page;

use function count;
use Cycle\ORM\Select;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator\ForeignKeys;
use Cycle\Schema\Generator\GenerateModifiers;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderModifiers;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Generator\ValidateEntities;
use Cycle\Schema\Registry as CycleRegistry;
use Laminas\Diactoros\Response\JsonResponse;
use ON\CMS\DataHandler;
use ON\DB\DatabaseConfig;
use ON\DB\Manager;
use ON\ORM\Compiler\CycleRegistryGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ItemsPage
{
	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected Manager $db,
		protected DataHandler $dataHandler
	) {

	}

	public function create(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}

	public function update(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}

	public function testUserRegistry($registry)
	{


		$dbal = $this->db->getDatabaseConnection("cycle");

		$registry = $this->dbCfg->getRegistry();

		$cycleRegistry = new CycleRegistry($dbal);
		$schema = (new Compiler())->compile($cycleRegistry, [
			new CycleRegistryGenerator($registry),
			new GenerateRelations(), // generate entity relations
			new GenerateModifiers(), // generate changes from schema modifiers
			new ValidateEntities(),  // make sure all entity schemas are correct
			new RenderTables(),      // declare table schemas
			new RenderRelations(),   // declare relation keys and indexes
			new RenderModifiers(),   // render all schema modifiers
			new ForeignKeys(),             // Define foreign key constraints
			//new Schema\Generator\SyncTables(),        // sync table changes to database
			new GenerateTypecast(),
		]);

		//$cycleRegistry = (new CycleRegistryGenerator($registry))->run($cycleRegistry);

		// get changes
		$users = $cycleRegistry->getTableSchema($cycleRegistry->getEntity("users"));


		if ($users->getComparator()->hasChanges()) {
			$comparator = $users->getComparator();
			$difference = [
				count($comparator->addedColumns()),
				count($comparator->droppedColumns()),
				count($comparator->alteredColumns()),
				count($comparator->addedIndexes()),
				count($comparator->droppedIndexes()),
				count($comparator->alteredIndexes()),
				count($comparator->addedForeignKeys()),
				count($comparator->droppedForeignKeys()),
				count($comparator->alteredForeignKeys()),
			];
			var_dump($comparator->alteredColumns());
			var_dump($difference);
		}
		echo "dsadsa";

		//var_dump($registry->getEntity("users")->getFields());

		exit;

		var_dump($dbUsers->getSchema());
	}

	public function all(ServerRequestInterface $request): ResponseInterface
	{

		//$this->testUserRegistry();

		$this->testUserRegistry($this->dbCfg->getRegistry());


		$query = $request->getQueryParams();
		$collection = $request->getAttribute("collection");
		$select = $this->dataHandler->getSelectQuery($collection, $query);

		/** @var Select $select */

		$all = $select->fetchData(true);
		//dd($all);

		//exit;

		return new JsonResponse($all);
	}

	public function getOne(ServerRequestInterface $request): ResponseInterface
	{
		$id = $request->getAttribute("id");
		echo "getOne";

		return new JsonResponse([ "id" => $id ]);
	}

	public function remove(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}
}
