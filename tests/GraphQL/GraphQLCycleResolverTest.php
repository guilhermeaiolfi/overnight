<?php

declare(strict_types=1);

namespace Tests\ON\GraphQL;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Factory;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Registry as CycleRegistry;
use ON\Data\Definition\Plugin\CycleCollectionPlugin;
use ON\Data\Definition\Registry;
use ON\DB\Cycle\Schema\CycleRegistryGenerator;
use ON\GraphQL\Error\GraphQLUserError;
use ON\GraphQL\Resolver\CycleResolver;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class GraphQLCycleResolverTest extends TestCase
{
	private Registry $registry;
	private DatabaseManager $databaseManager;
	private CycleResolver $resolver;

	protected function setUp(): void
	{
		$this->registry = new Registry();
		$this->registry->collection('project')
			->extend(CycleCollectionPlugin::class)->entity(Project::class)->mapper(Mapper::class)->end()
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('title', 'string')->end()
			->hasMany('tasks', 'task')->innerKey('id')->outerKey('project_id')->end()
			->end();
		$this->registry->collection('task')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('project_id', 'int')->end()
			->field('title', 'string')->end()
			->end();
		$this->registry->collection('note')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('title', 'string')->end()
			->end();
		$this->databaseManager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => ['default' => ['connection' => 'sqlite']],
			'connections' => ['sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig())],
		]));
		$database = $this->databaseManager->database();
		$database->execute('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
		$database->execute('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NOT NULL UNIQUE)');
		$database->execute('CREATE TABLE note (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');

		$schema = (new Compiler())->compile(new CycleRegistry($this->databaseManager), [
			new CycleRegistryGenerator($this->registry),
			new GenerateRelations(),
			new RenderTables(),
			new RenderRelations(),
			new GenerateTypecast(),
		]);
		$orm = new ORM(new Factory($this->databaseManager), new Schema($schema));
		$this->resolver = new CycleResolver($orm, $this->registry, defaultLimit: 1);
	}

	public function testUsesTheMappedEntityAndAppliesPaginationAtTheRepository(): void
	{
		$collection = $this->registry->getCollection('project');
		$this->assertInstanceOf(Project::class, $this->resolver->resolveCreate($collection, ['title' => 'Beta']));
		$this->resolver->resolveCreate($collection, ['title' => 'Alpha']);

		$result = $this->resolver->resolveCollection($collection, ['sort' => 'title']);
		$this->assertSame(2, $result['totalCount']);
		$this->assertCount(1, $result['items']);
		$this->assertSame('Alpha', $result['items'][0]->title);
		$this->assertSame(
			['items' => [], 'totalCount' => 2],
			$this->resolver->resolveCollection($collection, ['limit' => 0])
		);
	}

	public function testNestedCreateRollsBackParentAndChildWritesOnFailure(): void
	{
		$collection = $this->registry->getCollection('project');
		$project = $this->resolver->resolveNestedCreate(
			$collection,
			['title' => 'Existing'],
			['tasks' => [['title' => 'First task']]],
		);
		$this->assertSame('First task', $this->resolver->resolveRelation($project, $collection->relations->get('tasks'))[0]->title);

		try {
			$this->resolver->resolveNestedCreate(
				$collection,
				['title' => 'Temporary'],
				['tasks' => [['title' => 'Second task'], ['title' => 'First task']]],
			);
			$this->fail('The duplicate title must reject the nested write.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('Failed to create record with relations.', $error->getMessage());
			$this->assertNotNull($error->getPrevious());
			$database = $this->databaseManager->database();
			$this->assertSame(['Existing'], array_column($database->select('title')->from('project')->fetchAll(), 'title'));
			$this->assertSame(['First task'], array_column($database->select('title')->from('task')->fetchAll(), 'title'));
		}
	}

	public function testNestedCreateRejectsBelongsToWithoutChangingEitherTable(): void
	{
		$collection = $this->registry->getCollection('task');
		$this->registry->getCollection('project')->fields->get('id')->type('int');
		$collection->belongsTo('project', 'project')->innerKey('project_id')->outerKey('id')->end();

		try {
			$this->resolver->resolveNestedCreate(
				$collection,
				['title' => 'Temporary', 'project_id' => 1],
				['project' => ['title' => 'Parent']],
			);
			$this->fail('A belongs-to relation must reject the nested create.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('INVALID_INPUT', $error->getExtensions()['code']);
		}

		$database = $this->databaseManager->database();
		$this->assertSame([], $database->select('title')->from('project')->fetchAll());
		$this->assertSame([], $database->select('title')->from('task')->fetchAll());
	}

	public function testCreateSelectsTheCollectionRoleWhenTwoRolesUseDefaultEntities(): void
	{
		$task = $this->resolver->resolveCreate($this->registry->getCollection('task'), [
			'project_id' => 1,
			'title' => 'First task',
		]);

		$this->assertSame('First task', $task->title);
		$this->assertNull($this->resolver->resolveById($this->registry->getCollection('note'), (string) $task->id));
		$this->assertSame('First task', $this->resolver->resolveById($this->registry->getCollection('task'), (string) $task->id)->title);
	}
}

class Project
{
	public int $id;
	public string $title;
}
