<?php

declare(strict_types=1);

namespace ON\DB\Container;

use Clockwork\Support\Vanilla\Clockwork;
use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use ON\Clockwork\CycleDatabaseLogger;
use ON\CMS\Compiler\CycleCompiler;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use StdClass;

class CycleDatabaseFactory
{
	public function __invoke(Clockwork $clockwork, DatabaseConfig $dbCfg, string $name): CycleDatabase
	{
		$compiler = new CycleCompiler($dbCfg->getRegistry());
		$schema = $compiler->compile();
		//dd($schema);

		$schemaArr = [
			'user' => [
				Schema::ROLE => 'user',
				Schema::ENTITY => StdClass::class,
				Schema::MAPPER => StdMapper::class,
				Schema::DATABASE => 'default',
				Schema::TABLE => 'users',
				Schema::PRIMARY_KEY => 'id',
				Schema::COLUMNS => [
					'id',
					'name',
					'email',
					'password',
				],
				Schema::TYPECAST => [
					'id' => 'int',
				],
				Schema::SCHEMA => [],
				Schema::RELATIONS => [
					'parts' => [
						Relation::TYPE => Relation::HAS_MANY,
						Relation::TARGET => 'parts',
						Relation::SCHEMA => [
							Relation::NULLABLE => true,
							Relation::CASCADE => true,
							Relation::INNER_KEY => 'id',
							Relation::OUTER_KEY => 'user_id',
						],
					],
				],
			],
			'parts' => [
				Schema::ROLE => 'parts',
				Schema::ENTITY => StdClass::class,
				Schema::MAPPER => StdMapper::class,
				Schema::DATABASE => 'default',
				Schema::TABLE => 'parts',
				Schema::PRIMARY_KEY => 'id',
				Schema::COLUMNS => [
					'id',
					'name',
					'user_id',
				],
				Schema::TYPECAST => [
					'id' => 'int',
				],
				Schema::SCHEMA => [],
				Schema::RELATIONS => [
					'user' => [
						Relation::TYPE => Relation::REFERS_TO,
						Relation::TARGET => 'users',
						Relation::SCHEMA => [
							Relation::NULLABLE => true,
							Relation::CASCADE => true,
							Relation::INNER_KEY => 'user_id',
							Relation::OUTER_KEY => 'id',
						],
					],
				],
			],
		];
		$manager = new CycleDatabase($name, $dbCfg, $schema);

		if ($_ENV["APP_DEBUG"]) {
			$logger = new CycleDatabaseLogger($clockwork);
			$dbal = $manager->getConnection();
			$dbal->setLogger($logger);
		}

		return $manager;
	}
}
