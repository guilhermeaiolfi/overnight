<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemList;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;
use ON\ORM\Definition\Registry;

final class RestApiEventTest extends TestCase
{
	use RestApiTestFixtures;

	public function testItemListDefaultsToPendingAndCanTransitionStates(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$event = new ItemList($registry->getCollection('user'), []);

		$this->assertSame(AuthState::Pending, $event->getAuthState());

		$event->allow();
		$this->assertSame(AuthState::Allowed, $event->getAuthState());

		$event->requireAuthentication();
		$this->assertSame(AuthState::Unauthenticated, $event->getAuthState());

		$event->forbid();
		$this->assertSame(AuthState::Forbidden, $event->getAuthState());
	}

	public function testItemListAggregateHelpersNormalizeParams(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);

		$aggregateEvent = new ItemList($registry->getCollection('user'), [
			'aggregate' => ['count' => 'id'],
			'groupBy' => ['status'],
		]);
		$plainEvent = new ItemList($registry->getCollection('user'), []);

		$this->assertTrue($aggregateEvent->isAggregate());
		$this->assertSame(['count' => 'id'], $aggregateEvent->getAggregate());
		$this->assertSame(['status'], $aggregateEvent->getGroupBy());

		$this->assertFalse($plainEvent->isAggregate());
		$this->assertNull($plainEvent->getAggregate());
		$this->assertNull($plainEvent->getGroupBy());
	}
}
