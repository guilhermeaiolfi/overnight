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
		$collection = $registry->getCollection('user');
		$query = (new DirectusQueryParser())->parse($collection, []);
		$event = new ItemList($collection, $query);

		$this->assertSame(AuthState::Pending, $event->getAuthState());

		$event->allow();
		$this->assertSame(AuthState::Allowed, $event->getAuthState());

		$event->requireAuthentication();
		$this->assertSame(AuthState::Unauthenticated, $event->getAuthState());

		$event->forbid();
		$this->assertSame(AuthState::Forbidden, $event->getAuthState());
	}

	public function testItemListAggregateHelpersReadQuerySpec(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');
		$aggregateQuery = (new DirectusQueryParser())->parse($collection, [
			'aggregate' => ['count' => 'id'],
			'groupBy' => ['status'],
		]);
		$plainQuery = (new DirectusQueryParser())->parse($collection, []);

		$aggregateEvent = new ItemList($collection, $aggregateQuery);
		$plainEvent = new ItemList($collection, $plainQuery);

		$this->assertTrue($aggregateEvent->isAggregate());
		$this->assertSame('count', $aggregateEvent->getAggregate()[0]->responseFunction);
		$this->assertSame('id', $aggregateEvent->getAggregate()[0]->responseField);
		$this->assertSame('status', $aggregateEvent->getGroupBy()[0]->responseName);

		$this->assertFalse($plainEvent->isAggregate());
		$this->assertNull($plainEvent->getAggregate());
		$this->assertNull($plainEvent->getGroupBy());
	}
}
