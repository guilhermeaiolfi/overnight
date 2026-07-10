<?php

declare(strict_types=1);

namespace ON\DB\Container;

use Cycle\ORM\Schema;
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
use ON\Application;
use ON\Clockwork\CycleDatabaseLogger;
use ON\Data\Definition\Registry;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseConfig;
use ON\ORM\Compiler\CycleRegistryGenerator;

class CycleDatabaseFactory
{
	protected string $cacheFile;

	public function __construct(
		protected Application $app
	) {
	}

	public function __invoke(
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
			$logger = new CycleDatabaseLogger();
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

		$tmpFile = $this->cacheFile . '.tmp';
		file_put_contents($tmpFile, serialize($schema), LOCK_EX);
		rename($tmpFile, $this->cacheFile);
	}

	protected function readCache(Registry $registry, $dbal): ?array
	{
		if (! $this->isCacheClean($registry)) {
			return $this->compileSchema($registry, $dbal);
		}

		$cached = @unserialize((string) file_get_contents($this->cacheFile));
		if (is_array($cached)) {
			return $cached;
		}

		return $this->compileSchema($registry, $dbal);
	}

	protected function compileSchema(Registry $registry, $dbal): array
	{
		$cycleRegistry = new CycleRegistry($dbal);
		$schema = (new Compiler())->compile($cycleRegistry, [
			new CycleRegistryGenerator($registry),
			new GenerateRelations(),
			new GenerateModifiers(),
			new ValidateEntities(),
			new RenderTables(),
			new RenderRelations(),
			new RenderModifiers(),
			new ForeignKeys(),
			new GenerateTypecast(),
		]);

		$this->saveCache($schema);

		return $schema;
	}
}
