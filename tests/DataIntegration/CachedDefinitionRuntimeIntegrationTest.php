<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Schema\GeneratedField as CycleGeneratedField;
use Cycle\Schema\Registry as CycleRegistry;
use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\DataIntegration\DataExtension;
use ON\DataIntegration\Definition\DefinitionCache;
use ON\DataIntegration\Init\Event\DataDefinitionConfigureEvent;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\DB\Cycle\Schema\CycleRegistryGenerator;
use ON\DB\DatabaseExtension;
use ON\RestApi\Support\PrimaryKey;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cold build → cache → warm boot: Cycle + RestApi metadata without definition listeners.
 */
final class CachedDefinitionRuntimeIntegrationTest extends TestCase
{
	private string $previousCwd;

	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-cached-def-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir, 0777, true);
		mkdir($this->projectDir . '/config', 0777, true);
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=false\nAPP_ENV=testing\n");
		CachedDefinitionProbeExtension::reset();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		CachedDefinitionProbeExtension::reset();
		$this->removeDirectory($this->projectDir);
	}

	public function testWarmBootServesCycleAndRestApiWithoutDefinitionListeners(): void
	{
		$cold = $this->bootApplication(withProbe: true);
		$coldRegistry = $cold->ext('container')->getContainer()->get(Registry::class);
		$cache = $cold->ext('container')->getContainer()->get(DefinitionCache::class);

		$this->assertSame(1, CachedDefinitionProbeExtension::$configureCalls);
		$this->assertFileExists($cache->getFile());
		$this->assertTrue($coldRegistry->hasCollection('user'));
		$this->assertTrue($coldRegistry->hasCollection('post'));

		Application::$instance = null;
		CachedDefinitionProbeExtension::reset();

		$warm = $this->bootApplication(withProbe: true);
		$warmRegistry = $warm->ext('container')->getContainer()->get(Registry::class);

		$this->assertSame(0, CachedDefinitionProbeExtension::$configureCalls);
		$this->assertTrue($warmRegistry->hasCollection('user'));
		$this->assertTrue($warmRegistry->hasCollection('post'));
		$this->assertSame($coldRegistry->all(), $warmRegistry->all());

		$user = $warmRegistry->getCollection('user');
		$post = $warmRegistry->getCollection('post');
		$this->assertNotNull($user);
		$this->assertNotNull($post);

		$pk = PrimaryKey::of($user);
		$this->assertSame(['id'], $pk->getFieldNames());
		$this->assertSame(['id' => 7], $pk->getValue(7)->getValues());

		$author = $post->getRelation('author');
		$this->assertNotNull($author);
		$this->assertSame(['user_id'], $author->getInnerKeys());
		$this->assertSame('user', $author->getCollectionName());

		$posts = $user->getRelation('posts');
		$this->assertNotNull($posts);
		$this->assertTrue($posts->getCardinality()->isMany());

		$tags = $post->getRelation('tags');
		$this->assertNotNull($tags);
		$this->assertTrue($tags->isJunction());
		$this->assertSame('post_tag', $tags->through?->getCollectionName());

		$cycle = (new CycleRegistryGenerator($warmRegistry))->run($this->createCycleRegistry());
		$id = $cycle->getEntity('user')->getFields()->get('id');
		$this->assertTrue($id->isPrimary());
		$this->assertSame(CycleGeneratedField::ON_INSERT, $id->getGenerated());

		$authorRel = $cycle->getEntity('post')->getRelations()->get('author');
		$this->assertSame('belongsTo', $authorRel->getType());
		$this->assertSame('user', $authorRel->getTarget());
	}

	private function bootApplication(bool $withProbe): Application
	{
		$extensions = [
			ConfigExtension::class => [],
			ContainerExtension::class => [],
			DataExtension::class => [],
			DatabaseExtension::class => [],
		];
		if ($withProbe) {
			$extensions[CachedDefinitionProbeExtension::class] = [];
		}

		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => $extensions,
			'debug' => false,
		]);
	}

	private function createCycleRegistry(): CycleRegistry
	{
		$dbal = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig(),
				),
			],
		]));

		return new CycleRegistry($dbal);
	}

	private function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($directory);
	}
}

final class CachedDefinitionProbeExtension extends AbstractExtension
{
	public static int $configureCalls = 0;

	public static function reset(): void
	{
		self::$configureCalls = 0;
	}

	public function register(Init $init): void
	{
		$init->on(DataDefinitionConfigureEvent::class, [$this, 'onConfigure']);
	}

	public function onConfigure(DataDefinitionConfigureEvent $event): void
	{
		self::$configureCalls++;

		$event->registry->collection('user')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('email', 'string')->end()
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->end();

		$event->registry->collection('post')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('user_id', 'int')->end()
			->field('title', 'string')->end()
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->nullable(true)->end()
			->relation('tags', M2MRelation::class)
				->collection('tag')
				->innerKey('id')->outerKey('id')
				->through('post_tag')
					->innerKey('post_id')->outerKey('tag_id')
					->end()
				->end()
			->end();

		$event->registry->collection('tag')
			->primaryKey('id')
			->field('id', 'primary')->autoIncrement(true)->end()
			->field('name', 'string')->end()
			->end();

		$event->registry->collection('post_tag')
			->primaryKey('post_id', 'tag_id')
			->field('post_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
	}
}
