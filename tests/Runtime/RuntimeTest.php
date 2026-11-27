<?php

declare(strict_types=1);

namespace Tests\ON\Runtime;

use Closure;
use Exception;
use ON\Application;
use ON\Console\ConsoleExtension;
use ON\Middleware\PipelineExtension;
use ON\Runtime\ConsoleRuntime;
use ON\Runtime\HttpRuntime;
use ON\Runtime\RuntimeInterface;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[BackupGlobals(true)]
final class RuntimeTest extends TestCase
{
	private ?Application $previousApplication;

	protected function setUp(): void
	{
		$this->previousApplication = Application::$instance;
	}

	protected function tearDown(): void
	{
		Application::$instance = $this->previousApplication;
	}

	public function testExplicitHttpAndConsoleRuntimesWorkUnderCli(): void
	{
		$app = $this->createApplication(['runtime' => 'http']);
		$this->assertTrue($app->isCli());
		$this->assertNull($app->getActiveRuntime());

		$pipeline = $this->createMock(PipelineExtension::class);
		$pipeline->expects($this->exactly(3))->method('run')->willReturnCallback(function () use ($app): void {
			$this->assertSame('http', $app->getActiveRuntime());
		});
		$app->registerExtension(PipelineExtension::class, $pipeline);
		(new PipelineExtension($app))->register($app->init());

		$console = $this->createMock(ConsoleExtension::class);
		$console->expects($this->once())->method('run')->willReturnCallback(function () use ($app): int {
			$this->assertSame('console', $app->getActiveRuntime());

			return 23;
		});
		$app->registerExtension(ConsoleExtension::class, $console);
		(new ConsoleExtension($app))->register($app->init());

		$this->assertSame(0, $app->run('http'));
		$this->assertSame(23, $app->run('console'));
		$this->assertSame(0, $app->run(HttpRuntime::class));
		$this->assertSame(0, $app->run());
		$this->assertNull($app->getActiveRuntime());
	}

	public function testRuntimeInstancesCanBePassedDirectlyOrConfiguredAsDefault(): void
	{
		$default = $this->runtimeReturning(5, SampleRuntime::class);
		$explicit = $this->runtimeReturning(8, SampleRuntime::class);
		$app = $this->createApplication(['runtime' => $default]);

		$this->assertSame(5, $app->run());
		$this->assertSame(8, $app->run($explicit));
		$this->assertNull($app->getActiveRuntime());
	}

	#[DataProvider('sapiDefaults')]
	public function testZeroArgumentRuntimeSelection(bool $cli, string $default, int $exitCode): void
	{
		$http = $this->runtimeReturning(10, 'http');
		$console = $this->runtimeReturning(20, 'console');
		$runtimes = ['http' => $http, 'console' => $console];
		$app = $this->createApplication(['runtimes' => $runtimes], $cli);

		$this->assertSame($runtimes, $app->getRuntimes());
		$this->assertTrue($app->hasRuntime($default));
		$this->assertFalse($app->hasRuntime('unused'));
		$this->assertSame($runtimes[$default], $app->getRuntime($default));
		$this->assertSame($exitCode, $app->run());

		$sole = $this->createApplication(['runtimes' => ['worker' => $this->runtimeReturning(30, 'worker')]], $cli);
		$this->assertSame(30, $sole->run());
	}

	public static function sapiDefaults(): array
	{
		return ['cli' => [true, 'console', 20], 'web' => [false, 'http', 10]];
	}

	public function testNoMatchingDefaultListsRuntimesAndHowToSelectOne(): void
	{
		$app = $this->createApplication([
			'runtimes' => ['worker' => $this->createStub(RuntimeInterface::class), 'swoole' => $this->createStub(RuntimeInterface::class)],
		]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Registered runtimes: worker, swoole. Select one with $app->run("...")');
		$app->run();
	}

	public function testUnknownRuntimeListsRegisteredNames(): void
	{
		$app = $this->createApplication(['runtimes' => ['worker' => $this->createStub(RuntimeInterface::class)]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Runtime "missing" is not registered on the application. Registered runtimes: worker.');
		$app->run('missing');
	}

	public function testAmbiguousRuntimeClassRequiresARegisteredName(): void
	{
		$runtime = $this->createStub(RuntimeInterface::class);
		$app = $this->createApplication(['runtimes' => ['worker' => $runtime, 'other' => $runtime]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('matches multiple registered runtimes. Select one by name.');
		$app->run($runtime::class);
	}

	public function testRegisterRuntimeRejectsEmptyNames(): void
	{
		$app = $this->createApplication();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Runtime names must be non-empty.');
		$app->registerRuntime('', $this->createStub(RuntimeInterface::class));
	}

	public function testActiveRuntimeIsRestoredWhenANestedRuntimeThrows(): void
	{
		$app = $this->createApplication();
		$app->registerRuntime('outer', new SampleRuntime(function (Application $app): int {
			$this->assertSame('outer', $app->getActiveRuntime());

			try {
				return $app->run('inner');
			} finally {
				$this->assertSame('outer', $app->getActiveRuntime());
			}
		}));
		$app->registerRuntime('inner', new SampleRuntime(function (Application $app): int {
			$this->assertSame('inner', $app->getActiveRuntime());

			throw new RuntimeException('Runtime failed.');
		}));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Runtime failed.');

		try {
			$app->run('outer');
		} finally {
			$this->assertNull($app->getActiveRuntime());
		}
	}

	public function testConsoleRuntimeReturnsZeroForNullExitCode(): void
	{
		$app = $this->createApplication();
		$console = $this->createMock(ConsoleExtension::class);
		$console->expects($this->once())->method('run')->willReturn(null);
		$app->registerExtension(ConsoleExtension::class, $console);

		$this->assertSame(0, $app->run(new ConsoleRuntime()));
	}

	#[DataProvider('runtimeDependencies')]
	public function testRuntimeRequiresItsExtension(RuntimeInterface $runtime, string $message): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		$this->createApplication()->run($runtime);
	}

	public static function runtimeDependencies(): array
	{
		return [
			'http' => [new HttpRuntime(), 'HttpRuntime requires PipelineExtension.'],
			'console' => [new ConsoleRuntime(), 'ConsoleRuntime requires ConsoleExtension.'],
		];
	}

	private function createApplication(array $options = [], ?bool $cli = null): Application
	{
		$options += [
			'paths' => ['project' => getcwd()],
			'extensions' => [],
			'disable_dotenv' => true,
			'debug' => true,
		];

		if ($cli === null) {
			return new Application($options);
		}

		$app = $this->getMockBuilder(Application::class)->setConstructorArgs([$options])->onlyMethods(['isCli'])->getMock();
		$app->method('isCli')->willReturn($cli);

		return $app;
	}

	private function runtimeReturning(int $exitCode, string $name): RuntimeInterface
	{
		return new SampleRuntime(function (Application $app) use ($exitCode, $name): int {
			$this->assertSame($name, $app->getActiveRuntime());

			return $exitCode;
		});
	}
}

final class SampleRuntime implements RuntimeInterface
{
	public function __construct(private Closure $run)
	{
	}

	public function run(Application $app): int
	{
		return ($this->run)($app);
	}
}
