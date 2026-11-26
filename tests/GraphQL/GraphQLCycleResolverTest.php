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
use Cycle\ORM\Select\Repository;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Registry as CycleRegistry;
use ON\Data\Definition\Plugin\CycleCollectionPlugin;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
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
	private ORM $orm;
	private CycleResolver $resolver;

	protected function setUp(): void
	{
		$this->registry = new Registry();
		$this->registry->collection('project')
			->extend(CycleCollectionPlugin::class)->entity(Project::class)->mapper(Mapper::class)->end()
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('title', 'string')->end()
			->field('region', 'string')->column('site_region')->end()
			->hasMany('tasks', 'task')->innerKey('id')->outerKey('project_id')->end()
			->relation('labels', M2MRelation::class)
				->collection('label')
				->innerKey('id')
				->outerKey('id')
				->through('project_label')
					->innerKey('project_id')
					->outerKey('label_id')
					->end()
				->end()
			->end();
		$this->registry->collection('label')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('title', 'string')->end()
			->end();
		$this->registry->collection('project_label')
			->primaryKey('project_id', 'label_id')
			->field('project_id', 'int')->end()
			->field('label_id', 'int')->end()
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
		$database->execute("CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, site_region TEXT NOT NULL DEFAULT 'East')");
		$database->execute('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NOT NULL UNIQUE)');
		$database->execute('CREATE TABLE note (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
		$database->execute('CREATE TABLE label (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL)');
		$database->execute('CREATE TABLE project_label (project_id INTEGER NOT NULL, label_id INTEGER NOT NULL, PRIMARY KEY (project_id, label_id))');

		$this->orm = $this->buildOrm();
		$this->resolver = new CycleResolver($this->orm, $this->registry, defaultLimit: 1);
	}

	private function buildOrm(): ORM
	{
		$schema = (new Compiler())->compile(new CycleRegistry($this->databaseManager), [
			new CycleRegistryGenerator($this->registry),
			new GenerateRelations(),
			new RenderTables(),
			new RenderRelations(),
			new GenerateTypecast(),
		]);

		return new ORM(new Factory($this->databaseManager), new Schema($schema));
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

	public function testPaginationPreservesTheCustomRepositoryScope(): void
	{
		$collection = $this->registry->getCollection('project');
		$collection->extend(CycleCollectionPlugin::class)->repository(PublicProjectRepository::class);
		$resolver = new CycleResolver($this->buildOrm(), $this->registry, defaultLimit: 1);
		$database = $this->databaseManager->database();
		foreach (['Private', 'Beta', 'Alpha'] as $title) {
			$database->insert('project')->values(['title' => $title])->run();
		}

		$database->insert('project')->values(['title' => 'West project', 'site_region' => 'West'])->run();

		$result = $resolver->resolveCollection($collection, ['sort' => 'title', 'region' => 'East']);

		$this->assertSame(2, $result['totalCount']);
		$this->assertSame(['Alpha'], array_column($result['items'], 'title'));
		$this->assertSame(
			['items' => [], 'totalCount' => 2],
			$resolver->resolveCollection($collection, ['limit' => 0, 'region' => 'East'])
		);
	}

	public function testCycleRelationReadsRespectJunctionMembership(): void
	{
		$database = $this->databaseManager->database();
		$eagerId = $database->insert('project')->values(['title' => 'Eager project'])->run();
		$lazyId = $database->insert('project')->values(['title' => 'Lazy project'])->run();
		$database->insert('label')->values(['title' => 'First label'])->run();
		$database->insert('label')->values(['title' => 'Second label'])->run();

		$repository = $this->orm->getRepository('project');
		$relation = $this->registry->getCollection('project')->getRelation('labels');
		$eager = $repository->select()->load('labels')->wherePK($eagerId)->fetchOne();
		$lazy = $repository->findByPK($lazyId);

		$this->assertSame([], $this->resolver->resolveRelation($eager, $relation));
		$this->assertSame([], $this->resolver->resolveRelation($lazy, $relation));
		$this->assertSame([], $this->resolver->resolveRelation(['id' => $eagerId], $relation));

		$database->insert('project_label')->values(['project_id' => $lazyId, 'label_id' => 1])->run();
		$this->assertSame(
			['First label'],
			array_column($this->resolver->resolveRelation(['id' => $lazyId], $relation), 'title')
		);
	}

	public function testNestedPreparationUsesTheGeneratedParentKeyAndRollsBackRejection(): void
	{
		$collection = $this->registry->getCollection('project');
		$seen = [];

		try {
			$this->resolver->resolveNestedCreateWithChildPreparation(
				$collection,
				['title' => 'Temporary project'],
				['tasks' => [['title' => 'Pending task'], ['title' => 'Rejected task']]],
				static function (string $name, int $index, RelationInterface $relation, array $data) use (&$seen): void {
					$seen[] = [$name, $index, $relation->getOuterField()->getName(), $data['project_id']];
					if ($index === 1) {
						throw new GraphQLUserError('Child preparation rejected the write.', 'FORBIDDEN');
					}
				},
			);
			$this->fail('A nested preparation failure must abort the write.');
		} catch (GraphQLUserError $error) {
			$this->assertSame('FORBIDDEN', $error->getExtensions()['code']);
		}

		$this->assertSame([['tasks', 0, 'project_id', 1], ['tasks', 1, 'project_id', 1]], $seen);
		$database = $this->databaseManager->database();
		$this->assertSame([], $database->select('title')->from('project')->fetchAll());
		$this->assertSame([], $database->select('title')->from('task')->fetchAll());
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
	public string $region;
}

class PublicProjectRepository extends Repository
{
	public function findAll(array $scope = [], array $orderBy = []): iterable
	{
		$select = $this->select()->where('title', '!=', 'Private');
		if (isset($scope['site_region'])) {
			$select->where('region', $scope['site_region']);
		}

		return $select->orderBy($orderBy)->fetchAll();
	}
}
