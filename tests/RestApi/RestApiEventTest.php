<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Query\QueryContext;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class RestApiEventTest extends TestCase
{
	use RestApiTestFixtures;

	public function testItemListDefaultsToPendingAndCanTransitionStates(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');
		$context = new QueryContext();
		$query = $this->createQueryParser()->parse($collection, [], $context);
		$event = new ItemList($collection, $query, $context);

		$this->assertSame(AuthState::Pending, $event->getAuthState());

		$event->allow();
		$this->assertSame(AuthState::Allowed, $event->getAuthState());

		$event->requireAuthentication();
		$this->assertSame(AuthState::Unauthenticated, $event->getAuthState());

		$event->forbid();
		$this->assertSame(AuthState::Forbidden, $event->getAuthState());
	}

	public function testItemListAggregateHelpersReadContext(): void
	{
		$registry = new Registry();
		$this->createUserCollection($registry);
		$collection = $registry->getCollection('user');
		$parser = $this->createQueryParser();

		$aggregateContext = new QueryContext();
		$aggregateQuery = $parser->parse($collection, [
			'aggregate' => ['count' => 'id'],
			'groupBy' => ['name'],
		], $aggregateContext);

		$plainContext = new QueryContext();
		$plainQuery = $parser->parse($collection, [], $plainContext);

		$aggregateEvent = new ItemList($collection, $aggregateQuery, $aggregateContext);
		$plainEvent = new ItemList($collection, $plainQuery, $plainContext);

		$this->assertTrue($aggregateEvent->isAggregate());
		$this->assertSame('count', $aggregateEvent->getAggregate()[0]['function']);
		$this->assertSame('id', $aggregateEvent->getAggregate()[0]['field']);
		$this->assertSame('name', $aggregateEvent->getGroupBy()[0]['responseName']);

		$this->assertFalse($plainEvent->isAggregate());
		$this->assertNull($plainEvent->getAggregate());
		$this->assertNull($plainEvent->getGroupBy());
	}
}
