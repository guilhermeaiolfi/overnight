<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use ON\Mapper\Attribute\MapField;
use ON\Mapper\Blueprint\FieldBlueprintEntry;
use ON\Mapper\Blueprint\MappingBlueprint;
use ON\ORM\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class MappingBlueprintTest extends TestCase
{
	public function testResolvesFlatAndNestedPathsFromArray(): void
	{
		$blueprint = MappingBlueprint::fromArray([
			'title' => 'string',
			'meta' => [
				'created_at' => 'datetime',
			],
			'children' => StdClassNestedChildDto::class,
		]);

		$this->assertSame('string', $blueprint->resolve('title')?->type);
		$this->assertSame('datetime', $blueprint->resolve('meta.created_at')?->type);
		$this->assertSame(StdClassNestedChildDto::class, $blueprint->resolve('children')?->type);
	}

	public function testAcceptsFieldBlueprintEntryWithMapper(): void
	{
		$blueprint = MappingBlueprint::fromArray([
			'payload' => new FieldBlueprintEntry(
				StdClassNestedChildDto::class,
				\ON\Mapper\Structural\ArrayToObjectMapper::class,
			),
		]);

		$this->assertSame(
			\ON\Mapper\Structural\ArrayToObjectMapper::class,
			$blueprint->resolve('payload')?->mapperClass,
		);
	}

	public function testBuildsFromClassWithMapFieldAttribute(): void
	{
		$blueprint = MappingBlueprint::fromClass(BlueprintShape::class);

		$this->assertSame('datetime', $blueprint->resolve('starts_at')?->type);
		$this->assertNull($blueprint->resolve('author.name'));
	}

	public function testBuildsFromCollectionWithDepthLimitedRelations(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->field('id', 'int')->end()
			->field('published_at', 'datetime')->end()
			->hasMany('comments', 'comment')->innerKey('id')->outerKey('post_id')->end()
			->end();
		$registry->collection('user')
			->field('id', 'int')->primaryKey(true)->end()
			->field('joined_at', 'datetime')->end()
			->end();
		$registry->collection('comment')
			->field('id', 'int')->end()
			->field('author_id', 'int')->end()
			->field('posted_at', 'datetime')->end()
			->belongsTo('author', 'user')->innerKey('author_id')->outerKey('id')->end()
			->end();

		$rootOnly = MappingBlueprint::fromCollection($registry->getCollection('post'));
		$oneLevel = MappingBlueprint::fromCollection($registry->getCollection('post'), 1);
		$twoLevels = MappingBlueprint::fromCollection($registry->getCollection('post'), 2);

		$this->assertSame('datetime', $rootOnly->resolve('published_at')?->type);
		$this->assertNull($rootOnly->resolve('comments.posted_at'));
		$this->assertSame('datetime', $oneLevel->resolve('comments.posted_at')?->type);
		$this->assertNull($oneLevel->resolve('comments.author.joined_at'));
		$this->assertSame('datetime', $twoLevels->resolve('comments.author.joined_at')?->type);
	}

	public function testCollectionBlueprintResolvesListItemPaths(): void
	{
		$registry = new Registry();
		$registry->collection('post')
			->hasMany('comments', 'comment')->innerKey('id')->outerKey('post_id')->end()
			->end();
		$registry->collection('comment')
			->field('posted_at', 'datetime')->end()
			->end();

		$blueprint = MappingBlueprint::fromCollection($registry->getCollection('post'), 1);

		$this->assertSame('datetime', $blueprint->resolve('comments.0.posted_at')?->type);
	}

	public function testCollectionBlueprintRejectsNegativeDepth(): void
	{
		$registry = new Registry();
		$registry->collection('post')->field('id', 'int')->end()->end();

		$this->expectException(\InvalidArgumentException::class);

		MappingBlueprint::fromCollection($registry->getCollection('post'), -1);
	}
}

final class BlueprintShape
{
	#[MapField('datetime')]
	public string $starts_at = '';

	public BlueprintAuthorShape $author;
}

final class BlueprintAuthorShape
{
	public string $name = '';
}
