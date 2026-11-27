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
use ON\Container\ContainerExtension;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\Cycle\Schema\CycleRegistryGenerator;
use ON\DB\DatabaseConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

class CycleDatabaseFactory
{
	protected string $cacheFile;

	public function __construct(
		protected Application $app,
		protected ?ContainerInterface $container = null,
	) {
	}

	public function __invoke(
		DatabaseConfig $dbCfg,
		Registry $registry,
		string $name
	): CycleDatabase {
		$safeName = $this->sanitizeDatabaseName($name);
		// PHP-DI shares the factory while concurrent calls can build different schemas.
		$factory = clone $this;
		$factory->cacheFile = $this->app->paths->get('cache')->append("cycle.schema.{$safeName}.php")->getAbsolutePath();

		$manager = new CycleDatabase($name, $dbCfg);

		$dbal = $manager->getConnection();

		$schema = $factory->readCache($registry, $dbal);

		if (isset($schema)) {
			$manager->setSchema(new Schema($schema));
		}

		if ($this->app->isDebug()) {
			$logger = new CycleDatabaseLogger();
			$dbal->setLogger($logger);
		}

		return $manager;
	}

	protected function sanitizeDatabaseName(string $name): string
	{
		$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?? '';
		$safeName = preg_replace('/\.{2,}/', '_', $safeName) ?? '';
		$safeName = trim($safeName, '.');

		$prefix = $safeName === '' ? 'default' : substr($safeName, 0, 64);

		return $prefix . '.' . hash('sha256', $name);
	}

	protected function isCacheClean(Registry $registry): bool
	{
		if (! is_file($this->cacheFile)) {
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
		if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
			throw new RuntimeException(sprintf('Unable to create Cycle schema cache directory "%s".', $dir));
		}

		$tmpFile = $this->cacheFile . '.' . uniqid('', true) . '.tmp';
		$content = "<?php\n\nreturn " . var_export($schema, true) . ";\n";

		if (@file_put_contents($tmpFile, $content, LOCK_EX) !== strlen($content)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to write temporary Cycle schema cache file "%s".', $tmpFile));
		}

		if (! @rename($tmpFile, $this->cacheFile)) {
			@unlink($tmpFile);

			throw new RuntimeException(sprintf('Unable to move Cycle schema cache file into place at "%s".', $this->cacheFile));
		}

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($this->cacheFile, true);
		}
	}

	protected function readCache(Registry $registry, $dbal): ?array
	{
		if (! $this->isCacheClean($registry)) {
			return $this->compileSchema($registry, $dbal);
		}

		$cached = include $this->cacheFile;
		if (is_array($cached)) {
			return $cached;
		}

		return $this->compileSchema($registry, $dbal);
	}

	protected function compileSchema(Registry $registry, $dbal): array
	{
		$cycleRegistry = new CycleRegistry($dbal);
		$schema = (new Compiler())->compile($cycleRegistry, [
			new CycleRegistryGenerator($registry, $this->resolveConversionGateway()),
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

	protected function resolveConversionGateway(): ?ConversionGateway
	{
		$container = $this->container;
		if ($container === null && $this->app->hasExtension(ContainerExtension::class)) {
			$container = $this->app->ext(ContainerExtension::class)->getContainer();
		}

		if ($container !== null && $container->has(ConversionGateway::class)) {
			$gateway = $container->get(ConversionGateway::class);
			if ($gateway instanceof ConversionGateway) {
				return $gateway;
			}
		}

		return null;
	}
}
