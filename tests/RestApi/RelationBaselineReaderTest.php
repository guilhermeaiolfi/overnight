<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\Mapping;
use ON\Data\ORM\Record\RecordState;
use ON\RestApi\Mutation\RelationBaselineReader;
use ON\RestApi\Mutation\SessionFactory;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class RelationBaselineReaderTest extends TestCase
{
	use RestApiTestFixtures;

	protected function tearDown(): void
	{
		Mapping::resetDefaultGateway();
	}

	public function testLoadsRelationMembershipAsIdentitiesWithoutAdoptingReadState(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$items = $this->createItems($registry, $this->createFullDatabase());
		$sessions = new SessionFactory($this->runtimeForItems($items));
		$session = $sessions->create();
		$users = $registry->getCollection('user');
		$owner = RecordState::clean($users->getKey(1), ['id' => 1]);

		$baseline = (new RelationBaselineReader($items, $sessions))->loadToMany($session, $owner, $users->getRelation('posts'));

		$ids = [];
		foreach ($baseline->items() as $item) {
			$ids[] = $item->id;
			$this->assertSame(['id' => $item->id], get_object_vars($item));
		}
		sort($ids);
		$this->assertSame([1, 2], $ids);
		$this->assertCount(2, $session->getRecords()->getAll());
	}
}
