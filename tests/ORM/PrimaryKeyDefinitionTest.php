<?php

declare(strict_types=1);

namespace Tests\ON\ORM;

use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyDefinitionTest extends TestCase
{
	public function testSinglePrimaryKeyKeepsScalarUrlIdBehavior(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('article')
			->field('id', 'int')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('article');

		$identity = $collection->getPrimaryKey()->extract(['id' => 123]);

		$this->assertNotNull($identity);
		$this->assertSame(['id' => 123], $identity->getValues());
		$this->assertSame('123', $identity->toUrlId());
	}

	public function testCompositePrimaryKeyRoundTripsThroughStableUrlId(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->field('tenant_id', 'int')->column('tenant_id')->primaryKey(true)->end()
			->field('slug', 'string')->column('slug')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$primaryKey = $collection->getPrimaryKey();
		$identity = $primaryKey->extract(['tenant_id' => 10, 'slug' => 'home']);

		$this->assertNotNull($identity);
		$this->assertTrue($primaryKey->isComposite());
		$this->assertSame(['tenant_id', 'slug'], $primaryKey->getFieldNames());

		$decoded = $primaryKey->getValueFromUrlId($identity->toUrlId());

		$this->assertSame(['tenant_id' => 10, 'slug' => 'home'], $decoded->getValues());
	}

	public function testCompositePrimaryKeyCanExtractByColumnName(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->field('tenantId', 'int')->column('tenant_id')->primaryKey(true)->end()
			->field('slug', 'string')->column('page_slug')->primaryKey(true)->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$identity = $collection->getPrimaryKey()->extract([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]);

		$this->assertNotNull($identity);
		$this->assertSame(['tenantId' => 7, 'slug' => 'about'], $identity->getValues());
		$this->assertSame([], $collection->getPrimaryKey()->getMissingFieldNames([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]));
	}
}
