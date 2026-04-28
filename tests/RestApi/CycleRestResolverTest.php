<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleRestResolverTest extends TestCase
{
	use RestApiTestFixtures;

	public function testListWithBelongsToRelationFilter(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createCycleResolver($registry, $db);

		$result = $resolver->list($registry->getCollection('post'), [
			'filter' => ['author.name' => ['_eq' => 'John']],
		]);

		$this->assertCount(2, $result['items']);
		$this->assertSame(['PHP Tips', 'Draft Post'], array_column($result['items'], 'title'));
	}

	public function testListWithManyToManyRelationFilter(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createCycleResolver($registry, $db);

		$result = $resolver->list($registry->getCollection('post'), [
			'filter' => ['tags.name' => ['_eq' => 'GraphQL']],
		]);

		$this->assertCount(2, $result['items']);
		$this->assertSame(['PHP Tips', 'GraphQL Guide'], array_column($result['items'], 'title'));
	}

	public function testAggregateWithRelationFilter(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$resolver = $this->createCycleResolver($registry, $db);

		$result = $resolver->aggregate($registry->getCollection('post'), [
			'aggregate' => ['count' => 'id'],
			'filter' => ['author.name' => ['_eq' => 'John']],
		]);

		$this->assertSame(2, $result[0]['count']['id']);
	}
}
