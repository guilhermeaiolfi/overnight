<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Container\Executor\ExecutorInterface;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Action\RestActionRouter;
use ON\RestApi\Event\RestApiActivatedEvent;
use ON\RestApi\RestApiConfig;
use ON\RestApi\RestApiExtension;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class RestActionRouterTest extends TestCase
{
	public function testMatchesFastRouteParameters(): void
	{
		$config = new RestApiConfig();
		$config->addAction('get', 'GET', '{collection}/{id}', TestRestAction::class);

		$result = (new RestActionRouter($config->get('actions')))->match('GET', '/post/123');

		$this->assertTrue($result->isSuccess());
		$this->assertSame(TestRestAction::class, $result->getMatchedRoute()->getMiddleware());
		$this->assertSame(['collection' => 'post', 'id' => '123'], $result->getMatchedParams());
	}

	public function testMethodMismatchReturnsRouteResultFailure(): void
	{
		$config = new RestApiConfig();
		$config->addAction('get', 'GET', '{collection}/{id}', TestRestAction::class);

		$result = (new RestActionRouter($config->get('actions')))->match('POST', '/post/123');

		$this->assertTrue($result->isFailure());
		$this->assertTrue($result->isMethodFailure());
		$this->assertSame(['GET'], $result->getAllowedMethods());
	}

	public function testRestApiExtensionExecutesActionsThroughExecutor(): void
	{
		$container = new class () implements ContainerInterface {
			public function get(string $id): mixed
			{
				return match ($id) {
					TestRestAction::class => new TestRestAction(),
					ExecutorInterface::class => new class () implements ExecutorInterface {
						public function execute($callableOrMethodStr, array $args = [])
						{
							return $callableOrMethodStr($args['params'], $args['payload'], $args['options']);
						}

						public function getContainer(): ?ContainerInterface
						{
							return null;
						}
					},
					RestApiConfig::class => $this->createConfig(),
					EventDispatcherInterface::class => new class () implements EventDispatcherInterface {
						public function dispatch(object $event): object
						{
							return $event;
						}
					},
					default => throw new RuntimeException("Unknown service {$id}."),
				};
			}

			public function has(string $id): bool
			{
				return in_array($id, [
					TestRestAction::class,
					ExecutorInterface::class,
					RestApiConfig::class,
					EventDispatcherInterface::class,
				], true);
			}

			private function createConfig(): RestApiConfig
			{
				$config = new RestApiConfig();
				$config->endpointUri = '/items';

				return $config;
			}
		};

		$extension = (new ReflectionClass(RestApiExtension::class))->newInstanceWithoutConstructor();
		$containerProperty = new ReflectionProperty(RestApiExtension::class, 'container');
		$containerProperty->setValue($extension, $container);

		$result = $extension->execute(
			TestRestAction::class,
			['collection' => 'post'],
			['query' => ['limit' => 1]],
			['dispatchEvents' => false]
		);

		$this->assertSame([
			'params' => ['collection' => 'post'],
			'payload' => ['query' => ['limit' => 1]],
			'options' => ['dispatchEvents' => false],
		], $result);
	}

	public function testRestApiExtensionActivatesOnlyOnceOnFirstExecute(): void
	{
		$dispatcher = new class () implements EventDispatcherInterface {
			public int $activationCalls = 0;

			public function dispatch(object $event): object
			{
				if ($event instanceof RestApiActivatedEvent) {
					$this->activationCalls++;
				}

				return $event;
			}
		};

		$container = new class ($dispatcher) implements ContainerInterface {
			public function __construct(private EventDispatcherInterface $dispatcher)
			{
			}

			public function get(string $id): mixed
			{
				return match ($id) {
					TestRestAction::class => new TestRestAction(),
					ExecutorInterface::class => new class () implements ExecutorInterface {
						public function execute($callableOrMethodStr, array $args = [])
						{
							return $callableOrMethodStr($args['params'], $args['payload'], $args['options']);
						}

						public function getContainer(): ?ContainerInterface
						{
							return null;
						}
					},
					RestApiConfig::class => $this->createConfig(),
					EventDispatcherInterface::class => $this->dispatcher,
					default => throw new RuntimeException("Unknown service {$id}."),
				};
			}

			public function has(string $id): bool
			{
				return in_array($id, [
					TestRestAction::class,
					ExecutorInterface::class,
					RestApiConfig::class,
					EventDispatcherInterface::class,
				], true);
			}

			private function createConfig(): RestApiConfig
			{
				$config = new RestApiConfig();
				$config->endpointUri = '/items';

				return $config;
			}
		};

		$extension = (new ReflectionClass(RestApiExtension::class))->newInstanceWithoutConstructor();
		$containerProperty = new ReflectionProperty(RestApiExtension::class, 'container');
		$containerProperty->setValue($extension, $container);
		$configProperty = new ReflectionProperty(RestApiExtension::class, 'config');
		$configProperty->setValue($extension, $container->get(RestApiConfig::class));

		$extension->execute(TestRestAction::class, ['collection' => 'post']);
		$extension->execute(TestRestAction::class, ['collection' => 'post']);

		$this->assertSame(1, $dispatcher->activationCalls);
	}
}

final class TestRestAction implements RestActionInterface
{
	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		return [
			'params' => $params,
			'payload' => $payload,
			'options' => $options,
		];
	}
}
