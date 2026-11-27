<?php

declare(strict_types=1);

namespace Tests\ON\Init;

use InvalidArgumentException;
use ON\Init\Attribute\After;
use ON\Init\Attribute\Before;
use ON\Init\Attribute\InitEvent;
use ON\Init\Attribute\On;
use ON\Init\InitAttributeLoader;
use PHPUnit\Framework\TestCase;

final class InitAttributeLoaderTest extends TestCase
{
	public function testExtractsInferredAndExplicitRepeatableHooks(): void
	{
		$hooks = InitAttributeLoader::extractHooks(SampleAttributedExtension::class);

		$this->assertSame([
			[
				'method' => 'onBoot',
				'event' => SampleBootEvent::class,
				'stage' => 'on',
				'optional' => false,
			],
			[
				'method' => 'beforeAndAfter',
				'event' => SampleBootEvent::class,
				'stage' => 'before',
				'optional' => true,
			],
			[
				'method' => 'beforeAndAfter',
				'event' => SampleLazyEvent::class,
				'stage' => 'after',
				'optional' => false,
			],
		], $hooks);
	}

	public function testThrowsWhenEventCannotBeInferred(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no class-typed first parameter');

		InitAttributeLoader::extractHooks(InvalidAttributedExtension::class);
	}

	public function testExtractsInitEventMetadata(): void
	{
		$meta = InitAttributeLoader::extractEventMetadata(SampleLazyEvent::class);

		$this->assertSame([
			'owner' => SampleAttributedExtension::class,
			'lazy' => true,
		], $meta);

		$this->assertNull(InitAttributeLoader::extractEventMetadata(SampleBootEvent::class));
		$this->assertNull(InitAttributeLoader::extractEventMetadata('NonExistent\\EventClass'));
	}
}

final class SampleBootEvent
{
}

#[InitEvent(owner: SampleAttributedExtension::class, lazy: true)]
final class SampleLazyEvent
{
}

final class SampleAttributedExtension
{
	#[On]
	public function onBoot(SampleBootEvent $event): void
	{
	}

	#[Before(SampleBootEvent::class, optional: true)]
	#[After(SampleLazyEvent::class)]
	public function beforeAndAfter(): void
	{
	}
}

final class InvalidAttributedExtension
{
	#[On]
	public function missingParam(): void
	{
	}
}
