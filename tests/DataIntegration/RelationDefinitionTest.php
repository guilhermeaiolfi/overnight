<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use PHPUnit\Framework\TestCase;

final class RelationDefinitionTest extends TestCase
{
	public function testSingleKeyRelationPreservesLegacyFieldAccess(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();

		$relation = $registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->end()
			->relations->get('author');

		$this->assertSame(['user_id'], $relation->getInnerKeys());
		$this->assertSame(['id'], $relation->getOuterKeys());
		$this->assertSame('user_id', $relation->getInnerField()->getName());
		$this->assertSame('id', $relation->getOuterField()->getName());
	}

	public function testCompositeRelationLegacyFieldAccessThrows(): void
	{
		$registry = new Registry();
		$registry->collection('page')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();

		$relation = $registry->collection('article')
			->field('tenant_id', 'int')->end()
			->field('page_slug', 'string')->end()
			->belongsTo('page', 'page')
				->innerKey(['tenant_id', 'page_slug'])
				->outerKey(['tenant_id', 'slug'])
			->end()
			->relations->get('page');

		$this->assertSame(['tenant_id', 'page_slug'], $relation->getInnerKeys());
		$this->assertSame(['tenant_id', 'slug'], $relation->getOuterKeys());

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('getInnerField() is only available for single-key relations');
		$relation->getInnerField();
	}

	public function testInvalidCompositeRelationDefinitionIsRejected(): void
	{
		$registry = new Registry();
		$registry->collection('page')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('key count mismatch');

		$registry->collection('article')
			->field('tenant_id', 'int')->end()
			->field('page_slug', 'string')->end()
			->belongsTo('page', 'page')
				->innerKey(['tenant_id'])
				->outerKey(['tenant_id', 'slug']);
	}

	public function testCompositeManyToManyThroughKeysAreNormalized(): void
	{
		$registry = new Registry();
		$registry->collection('article')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();
		$registry->collection('tag')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();
		$registry->collection('article_tag')
			->field('article_tenant_id', 'int')->end()
			->field('article_slug', 'string')->end()
			->field('tag_tenant_id', 'int')->end()
			->field('tag_slug', 'string')->end()
			->end();

		$relation = $registry->getCollection('article')
			->relation('tags', M2MRelation::class)
			->collection('tag')
			->innerKey(['tenant_id', 'slug'])
			->outerKey(['tenant_id', 'slug'])
			->through('article_tag')
				->innerKey(['article_tenant_id', 'article_slug'])
				->outerKey(['tag_tenant_id', 'tag_slug'])
				->end();

		$this->assertSame(['tenant_id', 'slug'], $relation->getInnerKeys());
		$this->assertSame(['tenant_id', 'slug'], $relation->getOuterKeys());
		$this->assertSame(['article_tenant_id', 'article_slug'], $relation->through->getInnerKeys());
		$this->assertSame(['tag_tenant_id', 'tag_slug'], $relation->through->getOuterKeys());
	}
}
