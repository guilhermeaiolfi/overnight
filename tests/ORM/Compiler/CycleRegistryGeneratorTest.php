<?php

declare(strict_types=1);

namespace Tests\ON\ORM\Compiler;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\Schema\GeneratedField as CycleGeneratedField;
use Cycle\Schema\Registry as CycleRegistry;
use Cycle\Schema\Table\Column;
use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Registry as DataRegistry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Field\DateTimeFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\DataIntegration\DataExtension;
use ON\ORM\Compiler\CycleRegistryGenerator;
use ON\ORM\ORMExtension;
use ON\ORM\Select\Source;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

final class CycleRegistryGeneratorTest extends TestCase
{
	public function testScalarCollectionMetadataAndFields(): void
	{
		$registry = new DataRegistry();
		$registry->collection('users')
			->table('app_users')
			->database('tenant')
			->entity(UserEntity::class)
			->mapper(StdMapper::class)
			->repository(UserRepository::class)
			->scope(UserScope::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('email', 'string')->column('email_address')->maxLength(120)->end()
			->field('nickname', 'string')->nullable(true)->end()
			->field('status', 'string')->default('active')->end()
			->field('score', 'int')->default(0, castDefault: true)->end()
			->end();

		$entity = $this->compile($registry)->getEntity('users');

		$this->assertSame('users', $entity->getRole());
		$this->assertSame(UserEntity::class, $entity->getClass());
		$this->assertSame('tenant', $entity->getDatabase());
		$this->assertSame('app_users', $entity->getTableName());
		$this->assertSame(StdMapper::class, $entity->getMapper());
		$this->assertSame(UserRepository::class, $entity->getRepository());
		$this->assertSame(UserScope::class, $entity->getScope());
		$this->assertSame(Source::class, $entity->getSource());

		$id = $entity->getFields()->get('id');
		$this->assertSame('id', $id->getColumn());
		$this->assertSame('primary', $id->getType());
		$this->assertTrue($id->isPrimary());
		$this->assertSame(CycleGeneratedField::ON_INSERT, $id->getGenerated());

		$email = $entity->getFields()->get('email');
		$this->assertSame('email_address', $email->getColumn());
		$this->assertSame('string(120)', $email->getType());
		$this->assertFalse($email->isPrimary());

		$nickname = $entity->getFields()->get('nickname');
		$this->assertTrue($nickname->getOptions()->get(Column::OPT_NULLABLE));
		$this->assertNull($nickname->getOptions()->get(Column::OPT_DEFAULT));

		$status = $entity->getFields()->get('status');
		$this->assertSame('active', $status->getOptions()->get(Column::OPT_DEFAULT));

		$score = $entity->getFields()->get('score');
		$this->assertSame(0, $score->getOptions()->get(Column::OPT_DEFAULT));
		$this->assertTrue($score->getOptions()->get(Column::OPT_CAST_DEFAULT));
	}

	public function testCompositePrimaryKey(): void
	{
		$registry = new DataRegistry();
		$registry->collection('membership')
			->table('membership')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->column('tenant_fk')->end()
			->field('user_id', 'int')->column('user_fk')->end()
			->field('role', 'string')->end()
			->end();

		$entity = $this->compile($registry)->getEntity('membership');
		$fields = $entity->getFields();

		$this->assertTrue($fields->get('tenant_id')->isPrimary());
		$this->assertTrue($fields->get('user_id')->isPrimary());
		$this->assertFalse($fields->get('role')->isPrimary());
		$this->assertSame('tenant_fk', $fields->get('tenant_id')->getColumn());
		$this->assertSame('user_fk', $fields->get('user_id')->getColumn());
		$this->assertNull($fields->get('tenant_id')->getGenerated());
		$this->assertNull($fields->get('user_id')->getGenerated());
	}

	public function testManualIntPrimaryKeyIsNotGenerated(): void
	{
		$registry = new DataRegistry();
		$registry->collection('article')
			->table('article')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->end();

		$id = $this->compile($registry)->getEntity('article')->getFields()->get('id');

		$this->assertTrue($id->isPrimary());
		$this->assertSame('int', $id->getType());
		$this->assertNull($id->getGenerated());
	}

	public function testAutoIncrementIntPrimaryKeyIsGenerated(): void
	{
		$registry = new DataRegistry();
		$registry->collection('article')
			->table('article')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->end();

		$id = $this->compile($registry)->getEntity('article')->getFields()->get('id');

		$this->assertTrue($id->isPrimary());
		$this->assertSame(CycleGeneratedField::ON_INSERT, $id->getGenerated());
	}

	public function testSerialPrimaryKeyIsGenerated(): void
	{
		$registry = new DataRegistry();
		$registry->collection('article')
			->table('article')
			->primaryKey('id')
			->field('id', 'serial')->end()
			->end();

		$id = $this->compile($registry)->getEntity('article')->getFields()->get('id');

		$this->assertTrue($id->isPrimary());
		$this->assertSame(CycleGeneratedField::ON_INSERT, $id->getGenerated());
	}

	public function testCompositeKeyWithOneExplicitlyGeneratedComponent(): void
	{
		$registry = new DataRegistry();
		$registry->collection('shard_row')
			->table('shard_row')
			->primaryKey('shard_id', 'id')
			->field('shard_id', 'int')->end()
			->field('id', 'int')->autoIncrement(true)->end()
			->end();

		$fields = $this->compile($registry)->getEntity('shard_row')->getFields();

		$this->assertTrue($fields->get('shard_id')->isPrimary());
		$this->assertTrue($fields->get('id')->isPrimary());
		$this->assertNull($fields->get('shard_id')->getGenerated());
		$this->assertSame(CycleGeneratedField::ON_INSERT, $fields->get('id')->getGenerated());
	}

	public function testBasicRelations(): void
	{
		$registry = new DataRegistry();
		$registry->collection('user')
			->table('user')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->end();

		$registry->collection('post')
			->table('post')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->column('author_id')->end()
			->end();

		$registry->collection('profile')
			->table('profile')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->end()
			->end();

		$registry->getCollection('user')
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->hasOne('profile', 'profile')->exclusive(true)->innerKey('id')->outerKey('user_id')->cascade(false)->end()
			->end();

		$registry->getCollection('post')
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->nullable(true)->load('eager')->end()
			->end();

		$cycle = $this->compile($registry);

		$posts = $cycle->getEntity('user')->getRelations()->get('posts');
		$this->assertSame('post', $posts->getTarget());
		$this->assertSame('hasMany', $posts->getType());
		$this->assertSame('id', $posts->getOptions()->get('innerKey'));
		$this->assertSame('author_id', $posts->getOptions()->get('outerKey'));
		$this->assertTrue($posts->getOptions()->get('cascade'));
		$this->assertFalse($posts->getOptions()->get('nullable'));
		$this->assertSame('lazy', $posts->getOptions()->get('load'));

		$profile = $cycle->getEntity('user')->getRelations()->get('profile');
		$this->assertSame('profile', $profile->getTarget());
		$this->assertSame('hasOne', $profile->getType());
		$this->assertSame('id', $profile->getOptions()->get('innerKey'));
		$this->assertSame('user_id', $profile->getOptions()->get('outerKey'));
		$this->assertFalse($profile->getOptions()->get('cascade'));

		$author = $cycle->getEntity('post')->getRelations()->get('author');
		$this->assertSame('user', $author->getTarget());
		$this->assertSame('belongsTo', $author->getType());
		$this->assertSame('author_id', $author->getOptions()->get('innerKey'));
		$this->assertSame('id', $author->getOptions()->get('outerKey'));
		$this->assertTrue($author->getOptions()->get('nullable'));
		$this->assertSame('eager', $author->getOptions()->get('load'));
	}

	public function testManyToManyRelation(): void
	{
		$registry = new DataRegistry();
		$registry->collection('post')
			->table('post')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->relation('tags', M2MRelation::class)
				->collection('tag')
				->innerKey('id')
				->outerKey('id')
				->through('post_tag')
					->innerKey('post_id')
					->outerKey('tag_id')
					->end()
				->end()
			->end();

		$registry->collection('tag')
			->table('tag')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->end();

		$registry->collection('post_tag')
			->table('post_tag')
			->primaryKey('post_id', 'tag_id')
			->field('post_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();

		$relation = $this->compile($registry)->getEntity('post')->getRelations()->get('tags');

		$this->assertSame('tag', $relation->getTarget());
		$this->assertSame('manyToMany', $relation->getType());
		$this->assertSame('post_tag', $relation->getOptions()->get('through'));
		$this->assertSame('id', $relation->getOptions()->get('innerKey'));
		$this->assertSame('id', $relation->getOptions()->get('outerKey'));
		$this->assertSame('post_id', $relation->getOptions()->get('throughInnerKey'));
		$this->assertSame('tag_id', $relation->getOptions()->get('throughOuterKey'));
	}

	public function testCompositeManyToManyRelation(): void
	{
		$registry = new DataRegistry();
		$registry->collection('tenant_user')
			->table('tenant_user')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->column('tu_tenant')->end()
			->field('user_id', 'int')->column('tu_user')->end()
			->relation('roles', M2MRelation::class)
				->collection('tenant_role')
				->innerKey(['tenant_id', 'user_id'])
				->outerKey(['tenant_id', 'role_id'])
				->through('tenant_user_role')
					->innerKey(['pivot_tenant', 'pivot_user'])
					->outerKey(['pivot_tenant', 'pivot_role'])
					->end()
				->end()
			->end();

		$registry->collection('tenant_role')
			->table('tenant_role')
			->primaryKey('tenant_id', 'role_id')
			->field('tenant_id', 'int')->column('tr_tenant')->end()
			->field('role_id', 'int')->column('tr_role')->end()
			->end();

		$registry->collection('tenant_user_role')
			->table('tenant_user_role')
			->primaryKey('pivot_tenant', 'pivot_user', 'pivot_role')
			->field('pivot_tenant', 'int')->column('tur_tenant')->end()
			->field('pivot_user', 'int')->column('tur_user')->end()
			->field('pivot_role', 'int')->column('tur_role')->end()
			->end();

		$relation = $this->compile($registry)->getEntity('tenant_user')->getRelations()->get('roles');

		$this->assertSame('manyToMany', $relation->getType());
		$this->assertSame('tenant_role', $relation->getTarget());
		$this->assertSame('tenant_user_role', $relation->getOptions()->get('through'));
		$this->assertSame(['tu_tenant', 'tu_user'], $relation->getOptions()->get('innerKey'));
		$this->assertSame(['tr_tenant', 'tr_role'], $relation->getOptions()->get('outerKey'));
		$this->assertSame(['tur_tenant', 'tur_user'], $relation->getOptions()->get('throughInnerKey'));
		$this->assertSame(['tur_tenant', 'tur_role'], $relation->getOptions()->get('throughOuterKey'));
	}

	public function testExportRestoreProducesIdenticalCycleMetadata(): void
	{
		$fluent = new DataRegistry();
		$fluent->collection('user')
			->table('users')
			->database('default')
			->entity(stdClass::class)
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('email', 'string')->column('email_addr')->maxLength(80)->end()
			->end();
		$fluent->collection('post')
			->table('posts')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->end()
			->end();
		$fluent->getCollection('user')
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->end();
		$fluent->getCollection('post')
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->end()
			->end();

		$restored = new DataRegistry($fluent->all());

		$this->assertSame(
			$this->meaningfulCycleSnapshot($this->compile($fluent)),
			$this->meaningfulCycleSnapshot($this->compile($restored)),
		);
	}

	public function testFieldTypeHandlerAndStringLength(): void
	{
		$registry = new DataRegistry();
		$registry->collection('event')
			->table('event')
			->field('starts_at', DateTimeFieldType::class)->end()
			->field('code', StringFieldType::class)->maxLength(12)->end()
			->field('legacy', 'string(32)')->maxLength(255)->end()
			->end();

		$entity = $this->compile($registry)->getEntity('event');

		$this->assertSame('datetime', $entity->getFields()->get('starts_at')->getType());
		$this->assertSame('string(12)', $entity->getFields()->get('code')->getType());
		$this->assertSame('string(32)', $entity->getFields()->get('legacy')->getType());
	}

	public function testStringFieldDefaultsToMaxLength255(): void
	{
		$registry = new DataRegistry();
		$registry->collection('user')
			->table('user')
			->field('email', 'string')->end()
			->end();

		$email = $this->compile($registry)->getEntity('user')->getFields()->get('email');

		$this->assertSame('string(255)', $email->getType());
	}

	public function testNonStringFieldTypeIsUnchanged(): void
	{
		$registry = new DataRegistry();
		$registry->collection('post')
			->table('post')
			->field('content', 'text')->maxLength(255)->end()
			->end();

		$content = $this->compile($registry)->getEntity('post')->getFields()->get('content');

		$this->assertSame('text', $content->getType());
	}

	public function testUnknownFieldTypeThrows(): void
	{
		$registry = new DataRegistry();
		$registry->collection('user')
			->table('user')
			->field('status', 'status_enum')->end()
			->end();

		$this->expectException(FieldException::class);
		$this->expectExceptionMessage('Field(status) type "status_enum" is not a known Cycle column type');

		$this->compile($registry);
	}

	public function testUnknownClassFieldTypeThrows(): void
	{
		$registry = new DataRegistry();
		$registry->collection('user')
			->table('user')
			->field('status', stdClass::class)->end()
			->end();

		$this->expectException(FieldException::class);
		$this->expectExceptionMessage('Field(status) type "' . stdClass::class . '" is not a known Cycle column type');

		$this->compile($registry);
	}

	public function testRelationsWithoutPersistencePlannerAreSkippedInCycleSchema(): void
	{
		$registry = new DataRegistry();
		$registry->collection('user')
			->table('user')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->relation('latest_post', FirstOfManyRelation::class)
				->collection('post')
				->innerKey('id')
				->outerKey('user_id')
				->end()
			->end();
		$registry->collection('post')
			->table('post')
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->end()
			->end();

		$relations = $this->compile($registry)->getEntity('user')->getRelations();

		$this->assertTrue($relations->has('posts'));
		$this->assertSame('hasMany', $relations->get('posts')->getType());
		$this->assertFalse($relations->has('latest_post'));
		$this->assertNull(
			$registry->getCollection('user')->getRelations()->get('latest_post')->getPersistencePlanner()
		);
	}

	public function testContainerResolvesGeneratorWithDataRegistry(): void
	{
		$projectDir = sys_get_temp_dir() . '/overnight-ondata-cycle-' . bin2hex(random_bytes(8));
		mkdir($projectDir . '/config', 0777, true);
		file_put_contents($projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");

		$previousCwd = getcwd();
		chdir($projectDir);

		try {
			$app = new Application([
				'paths' => ['project' => $projectDir],
				'extensions' => [
					ConfigExtension::class => [],
					ContainerExtension::class => [],
					DataExtension::class => [],
					ORMExtension::class => [],
				],
				'debug' => true,
			]);

			$container = $app->ext('container')->getContainer();
			$generator = $container->get(CycleRegistryGenerator::class);

			$this->assertInstanceOf(CycleRegistryGenerator::class, $generator);
			$this->assertInstanceOf(DataRegistry::class, $container->get(DataRegistry::class));
			$this->assertInstanceOf(ConversionGateway::class, $container->get(ConversionGateway::class));
		} finally {
			chdir($previousCwd);
			Application::$instance = null;
			$this->removeDirectory($projectDir);
		}
	}

	private function compile(DataRegistry $registry): CycleRegistry
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
				'tenant' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig()
				),
			],
		]));

		$cycleRegistry = new CycleRegistry($manager);
		(new CycleRegistryGenerator($registry))->run($cycleRegistry);

		return $cycleRegistry;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function meaningfulCycleSnapshot(CycleRegistry $cycle): array
	{
		$snapshot = [];

		foreach ($cycle as $entity) {
			$role = $entity->getRole();
			$fields = [];
			foreach ($entity->getFields() as $name => $field) {
				$options = [];
				foreach ([Column::OPT_NULLABLE, Column::OPT_DEFAULT, Column::OPT_CAST_DEFAULT] as $option) {
					if ($field->getOptions()->has($option)) {
						$options[$option] = $field->getOptions()->get($option);
					}
				}

				$fields[$name] = [
					'column' => $field->getColumn(),
					'type' => $field->getType(),
					'primary' => $field->isPrimary(),
					'generated' => $field->getGenerated(),
					'typecast' => $field->getTypecast(),
					'options' => $options,
				];
			}

			$relations = [];
			foreach ($entity->getRelations() as $name => $relation) {
				$relations[$name] = [
					'target' => $relation->getTarget(),
					'type' => $relation->getType(),
					'load' => $relation->getOptions()->get('load'),
					'cascade' => $relation->getOptions()->get('cascade'),
					'nullable' => $relation->getOptions()->get('nullable'),
					'innerKey' => $relation->getOptions()->get('innerKey'),
					'outerKey' => $relation->getOptions()->get('outerKey'),
					'through' => $relation->getOptions()->has('through')
						? $relation->getOptions()->get('through')
						: null,
					'throughInnerKey' => $relation->getOptions()->has('throughInnerKey')
						? $relation->getOptions()->get('throughInnerKey')
						: null,
					'throughOuterKey' => $relation->getOptions()->has('throughOuterKey')
						? $relation->getOptions()->get('throughOuterKey')
						: null,
				];
			}

			$snapshot[$role] = [
				'class' => $entity->getClass(),
				'database' => $entity->getDatabase(),
				'table' => $entity->getTableName(),
				'mapper' => $entity->getMapper(),
				'repository' => $entity->getRepository(),
				'scope' => $entity->getScope(),
				'source' => $entity->getSource(),
				'fields' => $fields,
				'relations' => $relations,
			];
		}

		ksort($snapshot);

		return $snapshot;
	}

	private function removeDirectory(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($dir);
	}
}

final class UserEntity
{
}

final class UserRepository
{
}

final class UserScope
{
}
