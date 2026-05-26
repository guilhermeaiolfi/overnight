<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use ON\Mapper\Attribute\MapField;
use ON\Mapper\Blueprint\FieldBlueprintEntry;
use ON\Mapper\Blueprint\MappingBlueprint;
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
