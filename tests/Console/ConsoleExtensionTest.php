<?php

declare(strict_types=1);

namespace Tests\ON\Console;

use Fiber;
use ON\Application;
use ON\Cache\CacheExtension;
use ON\Console\Command\ClearCacheCommand;
use ON\Console\Command\RoutesCommand;
use ON\Console\Command\ServeCommand;
use ON\Console\ConsoleExtension;
use ON\Console\TailReader;
use ON\Container\ContainerExtension;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Init\Init;
use ON\Router\RouterExtension;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleExtensionTest extends TestCase
{
	public function testAddCommandDuringContainerReadyDoesNotCrashBeforeStart(): void
	{
		$app = $this->createMock(Application::class);
		$app->method('hasExtension')->willReturn(false);
		$app->method('isCli')->willReturn(true);

		$executor = $this->createMock(ExecutorInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($executor) {
			return match ($id) {
				ExecutorInterface::class => $executor,
				ServeCommand::class => new ServeCommand(),
				default => throw new RuntimeException("Unexpected service {$id}"),
			};
		});

		$console = new ConsoleExtension($app);
		$containerExt = $this->createMock(ContainerExtension::class);
		$console->onContainerReady(new ContainerReadyEvent($containerExt, $container));

		// Adding a command before start() must not crash on null $consoleApp
		$console->addCommand('demo:run', 'DemoController::index', 'Run demo');

		$this->assertTrue($console->getConsoleApp()->has('demo:run'));
	}

	public function testOnlyRegistersOptionalCommandsWhenExtensionsAreInstalled(): void
	{
		$resolved = [];
		$executor = $this->createMock(ExecutorInterface::class);

		$appWithoutOptional = $this->createMock(Application::class);
		$appWithoutOptional->method('hasExtension')->willReturn(false);
		$appWithoutOptional->method('isCli')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$resolved, $executor) {
			if ($id === ExecutorInterface::class) {
				return $executor;
			}
			$resolved[] = $id;

			return new ServeCommand();
		});

		$console = new ConsoleExtension($appWithoutOptional);
		$console->register(new Init());
		$console->onContainerReady(new ContainerReadyEvent($this->createMock(ContainerExtension::class), $container));
		$console->flush();

		$this->assertSame([ServeCommand::class], $resolved);

		// Calling flush() a second time should be a no-op since $this->q was reset
		$console->flush();
		$this->assertSame([ServeCommand::class], $resolved);
	}

	public function testRegistersClearCacheAndRoutesCommandsWhenExtensionsAreInstalled(): void
	{
		$resolved = [];
		$executor = $this->createMock(ExecutorInterface::class);

		$appWithAll = $this->createMock(Application::class);
		$appWithAll->method('hasExtension')->willReturnCallback(fn (string $ext): bool => in_array($ext, ['cache', 'router'], true));
		$appWithAll->method('isCli')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$resolved, $executor) {
			if ($id === ExecutorInterface::class) {
				return $executor;
			}
			$resolved[] = $id;
			$cmd = new Command();
			$cmd->setName('cmd:' . count($resolved));

			return $cmd;
		});

		$console = new ConsoleExtension($appWithAll);
		$init = new Init();
		$init->registerExtension($console);
		$init->registerExtension(new CacheExtension($appWithAll));
		$init->registerExtension(new RouterExtension($appWithAll));
		$console->onContainerReady(new ContainerReadyEvent($this->createMock(ContainerExtension::class), $container));
		$console->start($init->context());
		$console->flush();

		$this->assertSame([
			ServeCommand::class,
			ClearCacheCommand::class,
			RoutesCommand::class,
		], $resolved);
	}

	public function testQueuedCommandsCanBeRetriedAfterAFailedResolution(): void
	{
		$console = new ConsoleExtension($this->createMock(Application::class));
		$command = new Command('demo:retry');
		$resolutionCount = 0;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($command, &$resolutionCount) {
			return match ($id) {
				ExecutorInterface::class => $this->createStub(ExecutorInterface::class),
				ServeCommand::class => new ServeCommand(),
				Command::class => ++$resolutionCount === 1
					? throw new RuntimeException('Service not ready')
					: $command,
			};
		});
		$console->onContainerReady(new ContainerReadyEvent($this->createMock(ContainerExtension::class), $container));
		$console->addCommand(Command::class);

		try {
			$console->flush();
			$this->fail('Expected the first command resolution to fail.');
		} catch (RuntimeException $error) {
			$this->assertSame('Service not ready', $error->getMessage());
		}

		$console->flush();
		$this->assertSame($command, $console->getConsoleApp()->find('demo:retry'));
	}

	public function testServeCommandRejectsInvalidHostPortAndDirectory(): void
	{
		$tester = new CommandTester(new ServeCommand());

		$this->assertSame(Command::FAILURE, $tester->execute([
			'--host' => 'not a hostname',
			'--port' => '8000',
			'--publicDir' => sys_get_temp_dir(),
		]));

		$this->assertSame(Command::FAILURE, $tester->execute([
			'--host' => '127.0.0.1',
			'--port' => '99999',
			'--publicDir' => sys_get_temp_dir(),
		]));

		$this->assertSame(Command::FAILURE, $tester->execute([
			'--host' => '127.0.0.1',
			'--port' => '8000',
			'--publicDir' => sys_get_temp_dir() . '/non-existent-dir-' . bin2hex(random_bytes(6)),
		]));
	}

	public function testTailReaderThrowsOnMissingFile(): void
	{
		$reader = new TailReader();

		$this->expectException(RuntimeException::class);
		$reader->tail(sys_get_temp_dir() . '/missing-tail-file-' . bin2hex(random_bytes(6)));
	}

	public function testTailReaderHandlesEmptyFileInFiberWithoutSeekError(): void
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'tail_test_');
		$this->assertNotFalse($tmpFile);

		try {
			$reader = new TailReader();
			$fiber = new Fiber(function () use ($reader, $tmpFile): void {
				$reader->tail($tmpFile);
			});

			$fiber->start();
			$this->assertTrue($fiber->isSuspended());
		} finally {
			@unlink($tmpFile);
		}
	}
}
