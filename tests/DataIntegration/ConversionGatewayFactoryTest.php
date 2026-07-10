<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use FilesystemIterator;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingNode;
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
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
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
			static fn(string $resolver): bool => in_array($resolver, [
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
