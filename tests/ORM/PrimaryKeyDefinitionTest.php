<?php

declare(strict_types=1);

namespace Tests\ON\ORM;

use ON\Data\Definition\Registry;
use ON\RestApi\Support\PrimaryKey;
use PHPUnit\Framework\TestCase;

final class PrimaryKeyDefinitionTest extends TestCase
{
	public function testSinglePrimaryKeyKeepsScalarUrlIdBehavior(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('article')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('article');

		$identity = PrimaryKey::of($collection)->extractFromInput(['id' => 123]);

		$this->assertNotNull($identity);
		$this->assertSame(['id' => 123], $identity->getValues());
		$this->assertSame('123', PrimaryKey::of($collection)->toUrlId($identity));
	}

	public function testCompositePrimaryKeyRoundTripsThroughStableUrlId(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->column('tenant_id')->end()
			->field('slug', 'string')->column('slug')->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$primaryKey = PrimaryKey::of($collection);
		$identity = $primaryKey->extractFromInput(['tenant_id' => 10, 'slug' => 'home']);

		$this->assertNotNull($identity);
		$this->assertTrue($primaryKey->isComposite());
		$this->assertSame(['tenant_id', 'slug'], $primaryKey->getFieldNames());

		$decoded = $primaryKey->getValueFromUrlId($primaryKey->toUrlId($identity));

		$this->assertSame(['tenant_id' => 10, 'slug' => 'home'], $decoded->getValues());
	}

	public function testCompositePrimaryKeyCanExtractByColumnName(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('page')
			->primaryKey('tenantId', 'slug')
			->field('tenantId', 'int')->column('tenant_id')->end()
			->field('slug', 'string')->column('page_slug')->end()
			->field('title', 'string')->end()
			->end()
			->getCollection('page');

		$identity = PrimaryKey::of($collection)->extractFromRow([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]);

		$this->assertNotNull($identity);
		$this->assertSame(['tenantId' => 7, 'slug' => 'about'], $identity->getValues());
		$this->assertSame([], PrimaryKey::of($collection)->getMissingFieldNames([
			'tenant_id' => 7,
			'page_slug' => 'about',
		]));
	}
}
