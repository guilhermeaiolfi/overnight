<?php

declare(strict_types=1);

namespace Tests\ON\RestApi\Support;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\RestApi\Support\PrimaryKey;
use PHPUnit\Framework\TestCase;

/**
 * RestApi metadata compatibility against ON\Data definitions (QuerySpec layer unchanged).
 */
final class OnDataMetadataCompatibilityTest extends TestCase
{
	private Registry $registry;

	protected function setUp(): void
	{
		$this->registry = new Registry();

		$this->registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('email', 'string')->end()
			->hasMany('posts', 'post')->innerKey('id')->outerKey('user_id')->end()
			->end();

		$this->registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('user_id', 'int')->end()
			->field('title', 'string')->end()
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->end()
			->relation('tags', M2MRelation::class)
				->collection('tag')
				->innerKey('id')->outerKey('id')
				->through('post_tag')
					->innerKey('post_id')->outerKey('tag_id')
					->end()
				->end()
			->end();

		$this->registry->collection('tag')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('name', 'string')->end()
			->end();

		$this->registry->collection('post_tag')
			->primaryKey('post_id', 'tag_id')
			->field('post_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();

		$this->registry->collection('product')
			->primaryKey('region_id', 'sku')
			->field('region_id', 'int')->end()
			->field('sku', 'string')->end()
			->field('name', 'string')->end()
			->end();
	}

	public function testCollectionLookup(): void
	{
		$user = $this->registry->getCollection('user');
		$this->assertNotNull($user);
		$this->assertSame('user', $user->getName());
		$this->assertSame('user', $user->getTable());
	}

	public function testPrimaryKeyParsing(): void
	{
		$user = $this->registry->getCollection('user');
		$pk = PrimaryKey::of($user);

		$this->assertFalse($pk->isComposite());
		$this->assertSame(['id'], $pk->getFieldNames());
		$this->assertSame(['id'], $pk->getColumns());

		$identity = $pk->getValue(42);
		$this->assertSame(['id' => 42], $identity->values());
		$this->assertSame('42', $identity->toUrlId());
		$this->assertSame(['id' => '42'], $pk->getValueFromUrlId('42')->values());
	}

	public function testCompositePrimaryKeyParsing(): void
	{
		$product = $this->registry->getCollection('product');
		$pk = PrimaryKey::of($product);

		$this->assertTrue($pk->isComposite());
		$this->assertSame(['region_id', 'sku'], $pk->getFieldNames());

		$identity = $pk->extractFromInput(['region_id' => 1, 'sku' => 'ABC']);
		$this->assertNotNull($identity);
		$this->assertSame(['region_id' => 1, 'sku' => 'ABC'], $identity->values());

		$urlId = $identity->toUrlId();
		$roundTrip = $pk->getValueFromUrlId($urlId);
		$this->assertSame(['region_id' => 1, 'sku' => 'ABC'], $roundTrip->values());
	}

	public function testFieldSelection(): void
	{
		$post = $this->registry->getCollection('post');
		$this->assertTrue($post->hasField('title'));
		$this->assertSame('string', $post->getField('title')?->getType());
		$this->assertContains('title', $post->getVisibleFields());
	}

	public function testBelongsToMetadata(): void
	{
		$post = $this->registry->getCollection('post');
		$author = $post->getRelation('author');
		$this->assertNotNull($author);
		$this->assertSame('user', $author->getCollectionName());
		$this->assertSame(['user_id'], $author->getInnerKeys());
		$this->assertSame(['id'], $author->getOuterKeys());
		$this->assertTrue($author->getCardinality()->isSingle());
	}

	public function testHasManyMetadata(): void
	{
		$user = $this->registry->getCollection('user');
		$posts = $user->getRelation('posts');
		$this->assertNotNull($posts);
		$this->assertSame('post', $posts->getCollectionName());
		$this->assertSame(['id'], $posts->getInnerKeys());
		$this->assertSame(['user_id'], $posts->getOuterKeys());
		$this->assertTrue($posts->getCardinality()->isMany());
	}

	public function testManyToManyThroughMetadata(): void
	{
		$post = $this->registry->getCollection('post');
		$tags = $post->getRelation('tags');
		$this->assertNotNull($tags);
		$this->assertTrue($tags->isJunction());
		$this->assertSame('tag', $tags->getCollectionName());

		$through = $tags->through;
		$this->assertNotNull($through);
		$this->assertSame('post_tag', $through->getCollectionName());
		$this->assertSame(['post_id'], $through->getInnerKeys());
		$this->assertSame(['tag_id'], $through->getOuterKeys());
	}
}
