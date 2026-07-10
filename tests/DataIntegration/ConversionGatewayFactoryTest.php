<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapping;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\DataIntegration\DataExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ConversionGatewayFactoryTest extends TestCase
{
	private string $previousCwd;

	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = sys_get_temp_dir() . '/overnight-mapper-factory-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', 0777, true);
		file_put_contents($this->projectDir . '/.env', "APP_DEBUG=true\nAPP_ENV=testing\n");
		FreshResolverPerMapping::reset();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		Mapping::resetDefaultGateway();
		FreshResolverPerMapping::reset();
		$this->removeDirectory($this->projectDir);
	}

	public function testPrependedComponentsPreserveDeclarationOrder(): void
	{
		file_put_contents(
			$this->projectDir . '/config/data-mapper.all.php',
			<<<'PHP'
<?php

use ON\DataIntegration\Mapper\DataMapperConfig;
use Tests\ON\DataIntegration\FirstPrependedResolver;
use Tests\ON\DataIntegration\SecondPrependedResolver;

$config = new DataMapperConfig();
$config->prepend(FirstPrependedResolver::class);
$config->prepend(SecondPrependedResolver::class);

return $config;
PHP
		);

		$app = new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				DataExtension::class => [],
			],
			'debug' => true,
		]);

		$resolvers = $app->ext('container')
			->getContainer()
			->get(ConversionGateway::class)
			->getMapperManager()
			->getRegisteredResolvers();

		$prepended = array_values(array_filter(
			$resolvers,
			static fn (string $resolver): bool => in_array($resolver, [
				FirstPrependedResolver::class,
				SecondPrependedResolver::class,
			], true)
		));

		$this->assertSame(
			[FirstPrependedResolver::class, SecondPrependedResolver::class],
			$prepended,
			'Declared prepend order must be runtime precedence (first prepended is tried first).'
		);
		$this->assertSame(FirstPrependedResolver::class, $resolvers[0]);
	}

	public function testConstructorCreatesFreshResolverInstancesPerMapping(): void
	{
		file_put_contents(
			$this->projectDir . '/config/data-mapper.all.php',
			<<<'PHP'
<?php

use ON\DataIntegration\Mapper\DataMapperConfig;
use Tests\ON\DataIntegration\FreshResolverPerMapping;

$config = new DataMapperConfig();
$config->prepend(FreshResolverPerMapping::class);

return $config;
PHP
		);

		$app = new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				DataExtension::class => [],
			],
			'debug' => true,
		]);

		$gateway = $app->ext('container')->getContainer()->get(ConversionGateway::class);
		$manager = $gateway->getMapperManager();
		$options = new MappingOptions($gateway);

		$first = $this->freshResolversFrom($manager->createResolverChain($options));
		$second = $this->freshResolversFrom($manager->createResolverChain($options));

		$this->assertCount(1, $first);
		$this->assertCount(1, $second);
		$this->assertNotSame($first[0], $second[0]);
		$this->assertSame(
			2,
			FreshResolverPerMapping::$constructCalls,
			'MapperManager must receive a fresh resolver instance per mapping (make(), not get()).'
		);
	}

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 *
	 * @return list<FreshResolverPerMapping>
	 */
	private function freshResolversFrom(array $resolvers): array
	{
		return array_values(array_filter(
			$resolvers,
			static fn (NodeResolverInterface $resolver): bool => $resolver instanceof FreshResolverPerMapping
		));
	}

	public function testContainerReadyDoneInstallsDefaultGateway(): void
	{
		$app = new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				DataExtension::class => [],
			],
			'debug' => true,
		]);

		$gateway = $app->ext('container')->getContainer()->get(ConversionGateway::class);

		$this->assertSame($gateway, Mapping::getDefaultGateway());
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

final class FirstPrependedResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		return null;
	}
}

final class SecondPrependedResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		return null;
	}
}

final class FreshResolverPerMapping implements NodeResolverInterface
{
	public static int $constructCalls = 0;

	public function __construct()
	{
		self::$constructCalls++;
	}

	public static function reset(): void
	{
		self::$constructCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		return null;
	}
}
