<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DI\Container;
use Fiber;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerExtension;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Event\EventsExtension;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\Before;
use ON\Init\Attribute\On;
use ON\Init\Init;
use ON\Middleware\PipelineExtension;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Addon\RestApiAddonInterface;
use ON\RestApi\Event\RestApiActivatedEvent;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExecutor;
use ON\RestApi\RestApiExecutorInterface;
use ON\RestApi\RestApiExtension;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[BackupStaticProperties(true)]
final class RestApiExecutorTest extends TestCase
{
	public function testExecutesAndSharesLazyActivationWithoutAnHttpPipeline(): void
	{
		$app = $this->boot();
		$listener = $app->ext(RestApiActivationListener::class);
		$container = $app->ext(ContainerExtension::class)->getContainer();
		$config = $container->get(RestApiConfig::class);
		$this->assertSame('directus.list', $config->actions[0]['name'] ?? null);
		$config->endpointUri = '/test-items';
		$config->addAction('test.echo', 'GET', 'echo', EchoRestAction::class);

		$executor = $container->get(RestApiExecutorInterface::class);
		$this->assertInstanceOf(RestApiExecutorInterface::class, $executor);
		$this->assertSame($container->get(RestApiExecutor::class), $executor);
		$this->assertSame([], $listener->initEvents);
		$this->assertSame([], $listener->runtimeEvents);

		$this->assertSame(
			[['collection' => 'posts'], 'test payload', ['dispatchEvents' => false]],
			$executor->execute(new EchoRestAction(), ['collection' => 'posts'], 'test payload', ['dispatchEvents' => false]),
		);
		$this->assertCount(1, $listener->initEvents);
		$this->assertSame($listener->initEvents, $listener->runtimeEvents);
		$event = $listener->initEvents[0];
		$this->assertSame($container, $event->container);
		$this->assertSame($config, $event->config);
		$this->assertSame('/test-items', $event->endpointUri);

		$extension = $app->ext(RestApiExtension::class);
		$this->assertSame([['id' => 3], null, null], $extension->execute('test.echo', ['id' => 3]));
		$this->assertSame([[], null, null], $executor->execute(EchoRestAction::class));
		$extension->ensureActivated();
		$this->assertSame([$event], $listener->initEvents);
		$this->assertSame([$event], $listener->runtimeEvents);
	}

	public function testFirstHttpRequestUsesDefaultRoutesAndSharesActivationWithTheExecutor(): void
	{
		$app = $this->bootWithPipeline();
		$container = $app->ext(ContainerExtension::class)->getContainer();
		$container->set(ListAction::class, new EchoRestAction());
		$listener = $app->ext(RestApiActivationListener::class);
		$this->assertSame([], $listener->initEvents);
		$this->assertSame([], $listener->runtimeEvents);

		$response = $app->ext(PipelineExtension::class)->handle(new ServerRequest(uri: '/items/posts'));
		$this->assertSame(200, $response->getStatusCode());
		$this->assertCount(1, $listener->initEvents);
		$this->assertSame($listener->initEvents, $listener->runtimeEvents);

		$container->get(RestApiExecutorInterface::class)->ensureActivated();
		$this->assertCount(1, $listener->initEvents);
		$this->assertSame($listener->initEvents, $listener->runtimeEvents);
	}

	#[TestWith([false])]
	#[TestWith([true])]
	public function testRegistersAddonsOnceAndMountsMiddlewareWhenAPipelineIsPresent(bool $withPipeline): void
	{
		$options = ['addons' => [TrackingRestAddon::class => ['label' => 'configured']]];
		$app = $withPipeline ? $this->bootWithPipeline($options) : $this->boot(listenerOptions: $options);
		$addon = $app->ext(ContainerExtension::class)->getContainer()->get(TrackingRestAddon::class);

		$this->assertSame([['label' => 'configured', 'apiEndpointPath' => '/items']], $addon->registrations);
		if ($withPipeline) {
			$response = $app->ext(PipelineExtension::class)->handle(new ServerRequest(uri: '/items/addon'));
			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('{"addon":"configured"}', (string) $response->getBody());
		}
	}

	public function testRetriesRuntimeDispatchWithoutRepeatingInitEmission(): void
	{
		$app = $this->boot();
		$listener = $app->ext(RestApiActivationListener::class);
		$executor = $app->ext(ContainerExtension::class)->getContainer()->get(RestApiExecutorInterface::class);
		$dispatchAttempts = [];
		$failure = new RuntimeException('Activation interrupted');
		$app->ext(EventsExtension::class)->eventDispatcher->on(
			RestApiActivatedEvent::class,
			static function (RestApiActivatedEvent $event) use (&$dispatchAttempts, $failure): void {
				$dispatchAttempts[] = $event;
				if (count($dispatchAttempts) === 1) {
					throw $failure;
				}
			},
		);

		try {
			$executor->ensureActivated();
			$this->fail('Activation should propagate the runtime listener failure.');
		} catch (RuntimeException $exception) {
			$this->assertSame($failure, $exception);
		}
		$this->assertCount(1, $listener->initEvents);

		$executor->ensureActivated();
		$executor->ensureActivated();
		$this->assertCount(1, $listener->initEvents);
		$event = $listener->initEvents[0];
		$this->assertSame([$event, $event], $dispatchAttempts);
	}

	public function testRejectsReentrantExecutionAndCanRetryActivation(): void
	{
		$app = $this->boot();
		$executor = $app->ext(ContainerExtension::class)->getContainer()->get(RestApiExecutorInterface::class);
		$action = $this->createMock(RestActionInterface::class);
		$action->expects($this->never())->method('__invoke');
		$app->ext(EventsExtension::class)->eventDispatcher->once(RestApiActivatedEvent::class, static function () use ($executor, $action): void {
			$executor->execute($action);
		});

		try {
			$executor->ensureActivated();
			$this->fail('A nested action should be rejected during activation.');
		} catch (RuntimeException $exception) {
			$this->assertSame('REST API cannot execute actions while activation is in progress.', $exception->getMessage());
		}

		$this->assertSame([[], null, null], $executor->execute(EchoRestAction::class));
	}

	public function testRejectsConcurrentExecutionWhileActivationIsSuspended(): void
	{
		$app = $this->boot();
		$executor = $app->ext(ContainerExtension::class)->getContainer()->get(RestApiExecutorInterface::class);
		$app->ext(EventsExtension::class)->eventDispatcher->on(RestApiActivatedEvent::class, static function (): void {
			Fiber::suspend();
		});
		$action = $this->createMock(RestActionInterface::class);
		$action->expects($this->once())->method('__invoke')->willReturn('activated');
		$fiber = new Fiber(static fn (): mixed => $executor->execute($action));
		$fiber->start();
		$this->assertTrue($fiber->isSuspended());

		try {
			$executor->execute($action);
			$this->fail('An overlapping action should be rejected during activation.');
		} catch (RuntimeException $exception) {
			$this->assertSame('REST API cannot execute actions while activation is in progress.', $exception->getMessage());
		}

		$fiber->resume();
		$this->assertSame('activated', $fiber->getReturn());
	}

	public function testResolvesDefaultActionsInAnEarlyContainerReadyListener(): void
	{
		$app = $this->boot([EarlyRestActionListener::class => ['action' => 'directus.list']]);

		$this->assertSame([['collection' => 'posts'], null, null], $app->ext(EarlyRestActionListener::class)->result);
	}

	public function testPreservesActionsFromConfigFilesForEarlyContainerReadyListeners(): void
	{
		$previousCwd = getcwd();
		$project = sys_get_temp_dir() . '/rest-api-project-' . uniqid('', true);
		mkdir($project . '/config', recursive: true);
		$configFile = $project . '/config/restapi.all.php';
		file_put_contents($configFile, <<<'PHP'
			<?php
			return static function (\ON\RestApi\RestApiConfig $config): void {
				$config->addAction('custom.list', 'GET', '{collection}', \Tests\ON\RestApi\EchoRestAction::class);
			};
			PHP);

		try {
			$app = $this->boot([
				EarlyRestActionListener::class => ['action' => 'custom.list'],
				ContainerExtension::class => ['cache_path' => $project . '/container-cache'],
			], project: $project);
			$config = $app->ext(ContainerExtension::class)->getContainer()->get(RestApiConfig::class);
			$this->assertSame(['custom.list'], array_column($config->actions, 'name'));
			$this->assertSame([['collection' => 'posts'], null, null], $app->ext(EarlyRestActionListener::class)->result);
		} finally {
			chdir($previousCwd);
			unlink($configFile);
			rmdir($project . '/container-cache');
			rmdir($project . '/config');
			rmdir($project);
		}
	}

	public function testFallbackExecutorEmitsThroughBothChannelsWithTheContainerApplication(): void
	{
		$app = $this->boot();
		$listener = $app->ext(RestApiActivationListener::class);
		$container = new Container();
		$container->set(Application::class, $app);
		$container->set(EventDispatcherInterface::class, $app->ext(EventsExtension::class)->eventDispatcher);
		$extension = new RestApiExtension($app);
		$extension->onContainerReady(new ContainerReadyEvent($app->ext(ContainerExtension::class), $container));

		$extension->ensureActivated();
		$extension->ensureActivated();

		$this->assertCount(1, $listener->initEvents);
		$this->assertSame($listener->initEvents, $listener->runtimeEvents);
		$this->assertSame($container, $listener->initEvents[0]->container);
	}

	public function testHttpUsesTheCurrentExecutorBinding(): void
	{
		$app = $this->bootWithPipeline();
		$container = $app->ext(ContainerExtension::class)->getContainer();
		$executor = $this->createMock(RestApiExecutorInterface::class);
		$executor->expects($this->once())->method('ensureActivated');
		$executor->expects($this->once())->method('execute')
			->with(ListAction::class, ['collection' => 'posts'])
			->willReturn(['executor' => 'replacement']);
		$container->set(RestApiExecutorInterface::class, $executor);

		$response = $app->ext(PipelineExtension::class)->handle(new ServerRequest(uri: '/items/posts'));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('{"executor":"replacement"}', (string) $response->getBody());
	}

	private function bootWithPipeline(array $listenerOptions = []): Application
	{
		$pipelineFile = tempnam(sys_get_temp_dir(), 'rest-pipeline-');

		try {
			file_put_contents($pipelineFile, '<?php return static function (): void {};');

			return $this->boot([PipelineExtension::class => []], ['pipelineFile' => $pipelineFile] + $listenerOptions);
		} finally {
			unlink($pipelineFile);
		}
	}

	private function boot(array $extensions = [], array $listenerOptions = [], ?string $project = null): Application
	{
		return new Application([
			'paths' => ['project' => $project ?? getcwd()],
			'disable_dotenv' => true,
			'debug' => true,
			'extensions' => $extensions + [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				EventsExtension::class => [],
				RestApiExtension::class => [],
				RestApiActivationListener::class => $listenerOptions,
			],
		]);
	}
}

final class RestApiActivationListener extends AbstractExtension
{
	/** @var list<RestApiActivatedEvent> */
	public array $initEvents = [];
	/** @var list<RestApiActivatedEvent> */
	public array $runtimeEvents = [];

	#[On]
	public function onConfigConfigure(ConfigConfigureEvent $event): void
	{
		if (isset($this->options['pipelineFile'])) {
			$event->config->get(AppConfig::class)->set('app.pipeline_file', $this->options['pipelineFile']);
		}
		if (isset($this->options['addons'])) {
			$event->config->get(RestApiConfig::class)->addons = $this->options['addons'];
		}
	}

	public function register(Init $init): void
	{
		$this->app->ext(EventsExtension::class)->eventDispatcher->on(
			RestApiActivatedEvent::class,
			function (RestApiActivatedEvent $event): void {
				$this->runtimeEvents[] = $event;
			},
		);
	}

	#[On(optional: true)]
	public function onRestApiActivated(RestApiActivatedEvent $event): void
	{
		$this->initEvents[] = $event;
	}
}

final class EarlyRestActionListener extends AbstractExtension
{
	public mixed $result;

	#[Before]
	public function onContainerReady(ContainerReadyEvent $event): void
	{
		$event->container->set(ListAction::class, new EchoRestAction());
		$this->result = $event->container->get(RestApiExecutorInterface::class)
			->execute($this->options['action'], ['collection' => 'posts']);
	}
}

final class TrackingRestAddon implements RestApiAddonInterface, MiddlewareInterface
{
	public array $registrations = [];

	public function register(array $options = []): void
	{
		$this->registrations[] = $options;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return new JsonResponse(['addon' => $this->registrations[0]['label']]);
	}
}

final class EchoRestAction implements RestActionInterface
{
	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		return [$params, $payload, $options];
	}
}
