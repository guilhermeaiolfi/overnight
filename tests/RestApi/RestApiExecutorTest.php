<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DI\Container;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Config\AppConfig;
use ON\Config\ConfigExtension;
use ON\Config\Init\Event\ConfigConfigureEvent;
use ON\Container\ContainerExtension;
use ON\Event\EventDispatcher;
use ON\Event\EventsExtension;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Init\Init;
use ON\Middleware\PipelineExtension;
use ON\RestApi\Action\Directus\ListAction;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Event\RestApiActivatedEvent;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExecutor;
use ON\RestApi\RestApiExecutorInterface;
use ON\RestApi\RestApiExtension;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\TestCase;
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
		$pipelineFile = tempnam(sys_get_temp_dir(), 'rest-pipeline-');

		try {
			file_put_contents($pipelineFile, '<?php return static function (): void {};');
			$app = $this->boot([PipelineExtension::class => []], ['pipelineFile' => $pipelineFile]);
		} finally {
			unlink($pipelineFile);
		}
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

	public function testActivationCanRetryAfterAReentrantListenerThrows(): void
	{
		$dispatcher = new EventDispatcher();
		$executor = new RestApiExecutor(new Container(), new RestApiConfig(), $dispatcher);
		$calls = 0;
		$failure = new RuntimeException('Activation interrupted');
		$dispatcher->on(RestApiActivatedEvent::class, static function () use ($executor, &$calls, $failure): void {
			if (++$calls === 1) {
				$executor->ensureActivated();

				throw $failure;
			}
		});

		try {
			$executor->ensureActivated();
			$this->fail('Activation should propagate the listener failure.');
		} catch (RuntimeException $exception) {
			$this->assertSame($failure, $exception);
		}
		$this->assertSame(1, $calls);

		$executor->ensureActivated();
		$executor->ensureActivated();
		$this->assertSame(2, $calls);
	}

	private function boot(array $extensions = [], array $listenerOptions = []): Application
	{
		return new Application([
			'paths' => ['project' => getcwd()],
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

final class EchoRestAction implements RestActionInterface
{
	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		return [$params, $payload, $options];
	}
}
