<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Query\Parser\DirectusQueryParser;
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
		$query = (new DirectusQueryParser())->parse($registry->getCollection('user'), [
			'aggregate' => ['count' => 'id'],
			'groupBy' => ['status'],
		]);

		$aggregateEvent = new ItemList($registry->getCollection('user'), [
			'querySpec' => $query,
		]);
		$plainEvent = new ItemList($registry->getCollection('user'), []);

		$this->assertTrue($aggregateEvent->isAggregate());
		$this->assertSame('count', $aggregateEvent->getAggregate()[0]->responseFunction);
		$this->assertSame('id', $aggregateEvent->getAggregate()[0]->responseField);
		$this->assertSame('status', $aggregateEvent->getGroupBy()[0]->responseName);

		$this->assertFalse($plainEvent->isAggregate());
		$this->assertNull($plainEvent->getAggregate());
		$this->assertNull($plainEvent->getGroupBy());
	}
}
