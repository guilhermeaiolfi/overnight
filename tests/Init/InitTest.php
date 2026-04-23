<?php

declare(strict_types=1);

namespace Tests\ON\Init;

use ON\Init\Init;
use ON\Init\InitContext;
use ON\Init\InitException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class InitTest extends TestCase
{
	public function testDoneListenersRunAfterNormalListeners(): void
	{
		$init = new Init();
		$events = [];

		$init->on('orm.setup', function () use (&$events): void {
			$events[] = 'normal-1';
		});
		$init->on('orm.setup')->done(function () use (&$events): void {
			$events[] = 'done';
		});
		$init->on('orm.setup', function () use (&$events): void {
			$events[] = 'normal-2';
		});

		$init->emit('orm.setup', new stdClass());

		$this->assertSame(['normal-1', 'normal-2', 'done'], $events);
	}

	public function testNestedEventsFinishBeforeParentDoneListeners(): void
	{
		$init = new Init();
		$events = [];

		$init->on('parent', function (object $event, InitContext $context) use (&$events): void {
			$events[] = 'parent';
			$context->emit('child', new stdClass());
		});
		$init->on('parent')->done(function () use (&$events): void {
			$events[] = 'parent-done';
		});
		$init->on('child', function () use (&$events): void {
			$events[] = 'child';
		});
		$init->on('child')->done(function () use (&$events): void {
			$events[] = 'child-done';
		});

		$init->emit('parent', new stdClass());

		$this->assertSame(['parent', 'child', 'child-done', 'parent-done'], $events);
	}

	public function testListenersRegisteredDuringEmitDoNotRunForCurrentEmit(): void
	{
		$init = new Init();
		$events = [];

		$init->on('setup', function (object $event, InitContext $context) use ($init, &$events): void {
			$events[] = 'first';
			$init->on('setup', function () use (&$events): void {
				$events[] = 'late';
			});
		});

		$init->emit('setup', new stdClass());
		$init->emit('setup', new stdClass());

		$this->assertSame(['first', 'first', 'late'], $events);
	}

	public function testInitExceptionKeepsEventContext(): void
	{
		$init = new Init();

		$init->on('setup', function (): void {
			throw new RuntimeException('boom');
		});

		try {
			$init->emit('setup', new stdClass());
			$this->fail('Expected init exception.');
		} catch (InitException $e) {
			$this->assertSame('setup', $e->getEvent());
			$this->assertSame(['setup'], $e->getEventStack());
			$this->assertSame('boom', $e->getPrevious()->getMessage());
		}
	}
}
