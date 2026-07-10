<?php

declare(strict_types=1);

namespace Tests\ON\ORM\Compiler;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Mapper\StdMapper;
use Cycle\Schema\Definition\Entity;
use Cycle\Schema\Registry as CycleRegistry;
use Cycle\Schema\Table\Column;
use ON\Data\Definition\Registry as DataRegistry;
use ON\Data\Definition\Relation\M2MRelation as DataM2MRelation;
use ON\ORM\Compiler\CycleRegistryGenerator;
use ON\ORM\Compiler\OnDataCycleRegistryGenerator;
use ON\ORM\Definition\Registry as OrmRegistry;
use ON\ORM\Definition\Relation\M2MRelation as OrmM2MRelation;
use ON\ORM\Select\Source;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OnDataCycleRegistryGeneratorParityTest extends TestCase
{
	public function testSharedScalarAndRelationMetadataMatchesLegacyGenerator(): void
	{
		$orm = new OrmRegistry();
		$orm->collection('user')
			->table('users')
			->database('default')
			->entity(stdClass::class)
			->mapper(StdMapper::class)
			->source(Source::class)
			->field('id', 'primary')->primaryKey(true)->end()
			->field('email', 'string')->column('email_addr')->maxLength(80)->end()
			->field('bio', 'text')->nullable(true)->end()
			->field('status', 'string')->default('active')->end()
			->end();
		$orm->collection('post')
			->table('posts')
			->field('id', 'primary')->primaryKey(true)->end()
			->field('user_id', 'int')->column('author_id')->end()
			->end();
		$orm->collection('profile')
			->table('profiles')
			->field('id', 'primary')->primaryKey(true)->end()
			->field('user_id', 'int')->end()
			->end();
		$orm->collection('tag')
			->table('tags')
			->field('id', 'primary')->primaryKey(true)->end()
			->end();
		$orm->collection('post_tag')
			->table('post_tag')
			->field('post_id', 'int')->primaryKey(true)->end()
			->field('tag_id', 'int')->primaryKey(true)->end()
			->end();
		$orm->getCollection('user')
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->hasOne('profile', 'profile')->exclusive(true)->innerKey('id')->outerKey('user_id')->cascade(false)->end()
			->end();
		$orm->getCollection('post')
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->nullable(true)->load('eager')->end()
			->relation('tags', OrmM2MRelation::class)
				->collection('tag')
				->innerKey('id')
				->outerKey('id')
				->through('post_tag')
					->innerKey('post_id')
					->outerKey('tag_id')
					->end()
				->end()
			->end();

		$data = new DataRegistry();
		$data->collection('user')
			->table('users')
			->database('default')
			->entity(stdClass::class)
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('email', 'string')->column('email_addr')->maxLength(80)->end()
			->field('bio', 'text')->nullable(true)->end()
			->field('status', 'string')->default('active')->end()
			->end();
		$data->collection('post')
			->table('posts')
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->column('author_id')->end()
			->end();
		$data->collection('profile')
			->table('profiles')
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->field('user_id', 'int')->end()
			->end();
		$data->collection('tag')
			->table('tags')
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('id')
			->field('id', 'primary')->end()
			->end();
		$data->collection('post_tag')
			->table('post_tag')
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('post_id', 'tag_id')
			->field('post_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
		$data->getCollection('user')
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->hasOne('profile', 'profile')->exclusive(true)->innerKey('id')->outerKey('user_id')->cascade(false)->end()
			->end();
		$data->getCollection('post')
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->nullable(true)->load('eager')->end()
			->relation('tags', DataM2MRelation::class)
				->collection('tag')
				->innerKey('id')
				->outerKey('id')
				->through('post_tag')
					->innerKey('post_id')
					->outerKey('tag_id')
					->end()
				->end()
			->end();

		$this->assertSame(
			$this->meaningfulCycleSnapshot($this->compileLegacy($orm)),
			$this->meaningfulCycleSnapshot($this->compileOnData($data)),
		);
	}

	public function testCompositeIdentityParity(): void
	{
		$orm = new OrmRegistry();
		$orm->collection('membership')
			->table('membership')
			->mapper(StdMapper::class)
			->source(Source::class)
			->field('tenant_id', 'int')->column('tenant_fk')->primaryKey(true)->end()
			->field('user_id', 'int')->column('user_fk')->primaryKey(true)->end()
			->field('role', 'string')->end()
			->end();

		$data = new DataRegistry();
		$data->collection('membership')
			->table('membership')
			->mapper(StdMapper::class)
			->source(Source::class)
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->column('tenant_fk')->end()
			->field('user_id', 'int')->column('user_fk')->end()
			->field('role', 'string')->end()
			->end();

		$this->assertSame(
			$this->meaningfulCycleSnapshot($this->compileLegacy($orm)),
			$this->meaningfulCycleSnapshot($this->compileOnData($data)),
		);
	}

	private function compileLegacy(OrmRegistry $registry): CycleRegistry
	{
		$cycleRegistry = $this->newCycleRegistry();
		(new CycleRegistryGenerator($registry))->run($cycleRegistry);

		return $cycleRegistry;
	}

	private function compileOnData(DataRegistry $registry): CycleRegistry
	{
		$cycleRegistry = $this->newCycleRegistry();
		(new OnDataCycleRegistryGenerator($registry))->run($cycleRegistry);

		return $cycleRegistry;
	}

	private function newCycleRegistry(): CycleRegistry
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig()
				),
			],
		]));

		return new CycleRegistry($manager);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function meaningfulCycleSnapshot(CycleRegistry $cycle): array
	{
		$snapshot = [];

		foreach ($this->entities($cycle) as $entity) {
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

			ksort($fields);
			ksort($relations);

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

	/**
	 * @return list<Entity>
	 */
	private function entities(CycleRegistry $cycle): array
	{
		$entities = [];
		foreach ($cycle as $entity) {
			if ($entity instanceof Entity) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}
}
