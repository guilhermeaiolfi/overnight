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
use ON\Application;
use ON\Clockwork\CycleDatabaseLogger;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use ON\ORM\Compiler\CycleRegistryGenerator;
use ON\ORM\Definition\Registry;

class CycleDatabaseFactory
{
	protected string $cacheFile;

	public function __construct(
		protected Application $app
	) {
	}

	public function __invoke(
		Clockwork $clockwork,
		DatabaseConfig $dbCfg,
		Registry $registry,
		string $name
	): CycleDatabase {
		$this->cacheFile = $this->app->paths->get('cache')->append('cycle.schema.php')->getAbsolutePath();

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
		if (! file_exists($this->cacheFile)) {
			return false;
		} elseif (! $this->app->isDebug()) {
			return true;
		}

		$newer = 0;
		foreach ($registry->getDefinitionFiles() as $filename => $collections) {
			$mtime = filemtime($filename);
			if ($mtime > $newer) {
				$newer = $mtime;
			}
		}

		return $newer <= filemtime($this->cacheFile);
	}

	protected function saveCache(array $schema): void
	{
		$dir = dirname($this->cacheFile);
		if (! is_dir($dir)) {
			mkdir($dir, 0777, true);
		}

		file_put_contents($this->cacheFile, serialize($schema));
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

		return unserialize(file_get_contents($this->cacheFile));
	}
}
