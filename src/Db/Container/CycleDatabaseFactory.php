<?php

declare(strict_types=1);

namespace ON\DB\Container;

use Clockwork\Support\Vanilla\Clockwork;
use Cycle\Database\Database;
use Cycle\ORM\Schema;
use Cycle\Schema\Compiler;
use Cycle\Schema\Definition\Entity;
use Cycle\Schema\Generator\ForeignKeys;
use Cycle\Schema\Generator\GenerateModifiers;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderModifiers;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Generator\ValidateEntities;
use Cycle\Schema\Registry as CycleRegistry;
use ON\Clockwork\CycleDatabaseLogger;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use ON\ORM\Compiler\CycleRegistryGenerator;
use ON\ORM\Definition\Registry;

class CycleDatabaseFactory
{
	public const CACHE_FILE = "./var/cache/cycle.schema.php";

	public function __invoke(Clockwork $clockwork, DatabaseConfig $dbCfg, string $name): CycleDatabase
	{
		$registry = $dbCfg->getRegistry();

		$manager = new CycleDatabase($name, $dbCfg);

		$dbal = $manager->getConnection();

		$schema = $this->readCache($registry, $dbal);

		if (isset($schema)) {
			$manager->setSchema(new Schema($schema));
		}

		if ($_ENV["APP_DEBUG"]) {
			$logger = new CycleDatabaseLogger($clockwork);
			$dbal->setLogger($logger);
		}

		return $manager;
	}

	protected function isCacheClean(Registry $registry): bool
	{
		if (! file_exists(self::CACHE_FILE)) {
			return false;
		}

		$newer = 0;
		foreach ($registry->getDefinitionFiles() as $filename => $collections) {
			$mtime = filemtime($filename);
			if ($mtime > $newer) {
				$newer = $mtime;
			}
		}

		return $newer <= filemtime(self::CACHE_FILE);
	}

	protected function saveCache(array $schema): void
	{
		file_put_contents(self::CACHE_FILE, serialize($schema));
	}

	protected function readCache(Registry $registry, $dbal): ?array
	{
		if (! $this->isCacheClean($registry)) {
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

			$this->saveCache($schema);

			return $schema;
		}

		return unserialize(file_get_contents(self::CACHE_FILE));
	}
}
