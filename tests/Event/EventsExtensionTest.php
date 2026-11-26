<?php

declare(strict_types=1);

namespace Tests\ON\Event;

use Exception;
use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Container\ContainerExtension;
use ON\Container\Executor\ExecutorFactory;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Event\Attribute\EventHandler;
use ON\Event\Attribute\EventHandlerAttributeProcessor;
use ON\Event\EventsExtension;
use ON\Event\EventSubscriberInterface;
use ON\Event\NamedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class EventsExtensionTest extends TestCase
{
	public function testNamedEventAllowsNullSubject(): void
	{
		$eventWithoutSubject = new NamedEvent('app.ping');
		$this->assertSame('app.ping', $eventWithoutSubject->eventName());
		$this->assertNull($eventWithoutSubject->getSubject());

		$subject = new stdClass();
		$eventWithSubject = new NamedEvent('app.pong', $subject);
		$this->assertSame($subject, $eventWithSubject->getSubject());
	}

	public function testEventsExtensionWiresContainerAndHandlesPriorityOptionalSubscribers(): void
	{
		$app = $this->createMock(Application::class);
		$events = new EventsExtension($app);

		$subscriber = new SamplePriorityOptionalSubscriber();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with(SamplePriorityOptionalSubscriber::class)
			->willReturn($subscriber);

		$events->onContainerReady(new ContainerReadyEvent(
			$this->createMock(ContainerExtension::class),
			$container
		));

		$events->loadEventSubscriber(SamplePriorityOptionalSubscriber::class);

		$events->dispatch('test.optional');
		$events->dispatch('test.with_priority');

		$this->assertSame(['optional', 'with_priority'], $subscriber->calls);
	}

	public function testLoadEventSubscriberThrowsDescriptiveExceptionWithoutContainer(): void
	{
		$app = $this->createMock(Application::class);
		$events = new EventsExtension($app);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Container is not initialized');

		$events->loadEventSubscriber(SamplePriorityOptionalSubscriber::class);
	}

	public function testEventHandlerAnalyzeReflectionHandlesUntypedAndUnionParameters(): void
	{
		$attr = new EventHandler(name: 'custom.event', once: true);
		$classRef = new ReflectionClass(SampleAttributeHandler::class);
		$methodRef = new ReflectionMethod(SampleAttributeHandler::class, 'handleUntypedAndUnion');

		$attr->__analyzeReflection([$classRef, $methodRef, null]);

		$this->assertSame(SampleAttributeHandler::class, $attr->__declaringClass);
		$this->assertSame('handleUntypedAndUnion', $attr->__methodName);
		$this->assertSame([
			['untyped', null],
			['union', null],
		], $attr->__parameters);
	}

	public function testEventHandlerAttributeProcessorRespectsExplicitNameAndOnce(): void
	{
		$app = $this->createMock(Application::class);
		$events = new EventsExtension($app);
		$app->method('__get')->with('events')->willReturn($events);

		$handler = new SampleAttributeHandler();
		$container = $this->createMock(ContainerInterface::class);
		$executor = (new ExecutorFactory())($container);
		$container->method('get')->willReturnMap([
			[ExecutorInterface::class, $executor],
			[SampleAttributeHandler::class, $handler],
		]);

		$explicitOnce = new EventHandler(name: 'explicit.event', once: true);
		$explicitOnce->__declaringClass = SampleAttributeHandler::class;
		$explicitOnce->__methodName = 'onNamed';
		$explicitOnce->__parameters = [['event', NamedEvent::class]];

		$inferred = new EventHandler();
		$inferred->__declaringClass = SampleAttributeHandler::class;
		$inferred->__methodName = 'onTyped';
		$inferred->__parameters = [['event', SampleDomainEvent::class]];

		$reader = $this->createMock(AttributeReader::class);
		$reader->method('getAttributes')->willReturn([$explicitOnce, $inferred]);

		$processor = new EventHandlerAttributeProcessor($app, $container);
		$processor($reader);

		// Explicit name should be respected even though parameter is typed NamedEvent, and once=true should fire only once
		$events->dispatch('explicit.event');
		$events->dispatch('explicit.event');
		$this->assertSame(1, $handler->namedCalls);

		// Inferred event name from parameter type should fire every time (once=false)
		$events->dispatch(new SampleDomainEvent());
		$events->dispatch(new SampleDomainEvent());
		$this->assertSame(2, $handler->typedCalls);
	}
}

final class SamplePriorityOptionalSubscriber implements EventSubscriberInterface
{
	/** @var string[] */
	public array $calls = [];

	public static function getSubscribedEvents(): array
	{
		return [
			'test.optional' => ['onOptional'],
			'test.with_priority' => ['onWithPriority', 10],
		];
	}

	public function onOptional(): void
	{
		$this->calls[] = 'optional';
	}

	public function onWithPriority(): void
	{
		$this->calls[] = 'with_priority';
	}
}

final class SampleDomainEvent
{
}

final class SampleAttributeHandler
{
	public int $namedCalls = 0;
	public int $typedCalls = 0;

	public function handleUntypedAndUnion($untyped, string|int $union): void
	{
	}

	public function onNamed(NamedEvent $event): void
	{
		$this->namedCalls++;
	}

	public function onTyped(SampleDomainEvent $event): void
	{
		$this->typedCalls++;
	}
}
