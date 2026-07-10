<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use DateTimeImmutable;
use Laminas\Diactoros\ServerRequest;
use ON\Mapper\Attribute\MapFrom;
use ON\Mapper\Attribute\MapTo;
use function ON\Mapper\map;
use ON\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class NestedObjectMapperTest extends TestCase
{
	public function testMapsNestedObjectProperty(): void
	{
		$parent = map([
			'name' => 'parent',
			'child' => ['name' => 'child'],
		])->to(NestedParentDto::class);

		$this->assertInstanceOf(NestedChildDto::class, $parent->child);
		$this->assertSame('child', $parent->child->name);
	}

	public function testMapsNestedObjectWithoutRepresentationHint(): void
	{
		$parent = map([
			'obj' => ['name' => 'nested'],
		])->to(NestedObjectHolderDto::class);

		$this->assertInstanceOf(NestedChildDto::class, $parent->obj);
		$this->assertSame('nested', $parent->obj->name);
	}

	public function testMapsNestedObjectList(): void
	{
		$parent = map([
			'name' => 'parent',
			'children' => [
				['name' => 'b'],
				['name' => 'c'],
			],
		])->to(NestedParentWithChildrenDto::class);

		$this->assertCount(2, $parent->children);
		$this->assertContainsOnlyInstancesOf(NestedChildOfParentWithChildrenDto::class, $parent->children);
		$this->assertSame('b', $parent->children[0]->name);
		$this->assertSame('c', $parent->children[1]->name);
	}

	public function testMapsDotNotationKeys(): void
	{
		$book = map([
			'title' => 'Timeline Taxi',
			'author.name' => 'Jane',
			'author.email' => 'jane@example.com',
		])->to(NestedBookDto::class);

		$this->assertSame('Timeline Taxi', $book->title);
		$this->assertSame('Jane', $book->author->name);
		$this->assertSame('jane@example.com', $book->author->email);
	}

	public function testMapsNestedKeyAliasWithMapFrom(): void
	{
		$book = map([
			'title' => 'Timeline Taxi',
			'user' => ['name' => 'Jane', 'email' => 'jane@example.com'],
		])->to(NestedBookWithUserAliasDto::class);

		$this->assertSame('Jane', $book->author->name);
	}

	public function testMapsDotNotationWithMapFromAlias(): void
	{
		$book = map([
			'user.name' => 'Jane',
			'user.email' => 'jane@example.com',
		])->to(NestedBookWithUserAliasDto::class);

		$this->assertSame('Jane', $book->author->name);
		$this->assertSame('jane@example.com', $book->author->email);
	}

	public function testMapsNestedKeyAliasToArrayWithMapTo(): void
	{
		$book = new NestedBookWithUserAliasDto();
		$book->title = 'Timeline Taxi';
		$book->author = new NestedAuthorDto();
		$book->author->name = 'Jane';
		$book->author->email = 'jane@example.com';

		$array = map($book)->toArray();

		$this->assertArrayHasKey('user', $array);
		$this->assertSame('Jane', $array['user']['name']);
	}

	public function testWiresParentBackReferences(): void
	{
		$parent = map([
			'name' => 'a',
			'child' => ['name' => 'b'],
		])->to(NestedParentDto::class);

		$this->assertSame('a', $parent->child->parent->name);
		$this->assertSame('a', $parent->child->parentCollection[0]->name);
	}

	public function testWiresParentBackReferencesForChildrenList(): void
	{
		$parent = map([
			'name' => 'a',
			'children' => [['name' => 'b'], ['name' => 'c']],
		])->to(NestedParentWithChildrenDto::class);

		$this->assertSame('a', $parent->children[0]->parent->name);
		$this->assertSame('a', $parent->children[1]->parent->name);
	}

	public function testMapsWireNestedDatetimeInbound(): void
	{
		$book = map([
			'title' => 'Book',
			'author' => ['created_at' => '2024-03-15T10:30:00+00:00'],
		], WireRepresentation::class)->to(NestedWireBookDto::class);

		$this->assertInstanceOf(DateTimeImmutable::class, $book->author->createdAt);
		$this->assertSame('2024-03-15 10:30:00', $book->author->createdAt->format('Y-m-d H:i:s'));
	}

	public function testMapsWireNestedDatetimeOutbound(): void
	{
		$book = new NestedWireBookDto();
		$book->title = 'Book';
		$book->author = new NestedWireAuthorDto();
		$book->author->createdAt = new DateTimeImmutable('2024-03-15T10:30:00+00:00');

		$array = map($book)->as(WireRepresentation::class)->toArray();

		$this->assertSame('2024-03-15T10:30:00+00:00', $array['author']['created_at']);
	}

	public function testMapsPsrRequestWithDotNotation(): void
	{
		$request = $this->requestWithBody([
			'title' => 'Timeline Taxi',
			'author.name' => 'Jane',
			'author.email' => 'jane@example.com',
		]);

		$book = map($request)->to(NestedBookDto::class);

		$this->assertSame('Jane', $book->author->name);
	}

	public function testMapsPsrRequestWithNestedUserAlias(): void
	{
		$request = $this->requestWithBody([
			'user.name' => 'Jane',
			'user.email' => 'jane@example.com',
		]);

		$book = map($request)->to(NestedBookWithUserAliasDto::class);

		$this->assertSame('Jane', $book->author->name);
	}

	public function testMapsNestedObjectListOutbound(): void
	{
		$parent = new NestedParentWithChildrenDto();
		$parent->name = 'parent';
		$child = new NestedChildOfParentWithChildrenDto();
		$child->name = 'child';
		$parent->children = [$child];

		$array = map($parent)->toArray();

		$this->assertIsArray($array['children']);
		$this->assertSame('child', $array['children'][0]['name']);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function requestWithBody(array $body): ServerRequestInterface
	{
		return (new ServerRequest(uri: '/books', method: 'POST'))->withParsedBody($body);
	}
}

final class NestedAuthorDto
{
	public string $name = '';
	public string $email = '';
}

final class NestedBookDto
{
	public string $title = '';
	public NestedAuthorDto $author;
}

final class NestedBookWithUserAliasDto
{
	public string $title = '';
	#[MapFrom('user')]
	#[MapTo('user')]
	public NestedAuthorDto $author;
}

final class NestedChildDto
{
	public string $name = '';
	public NestedParentDto $parent;
	/** @var NestedParentDto[] */
	public array $parentCollection = [];
}

final class NestedParentDto
{
	public string $name = '';
	public NestedChildDto $child;
}

final class NestedParentWithChildrenDto
{
	public string $name = '';
	/** @var list<NestedChildOfParentWithChildrenDto> */
	public array $children = [];
}

final class NestedChildOfParentWithChildrenDto
{
	public string $name = '';
	public NestedParentWithChildrenDto $parent;
	/** @var NestedParentWithChildrenDto[] */
	public array $parentCollection = [];
}

final class NestedObjectHolderDto
{
	public NestedChildDto $obj;
}

final class NestedWireAuthorDto
{
	#[MapFrom('created_at')]
	#[MapTo('created_at')]
	public DateTimeImmutable $createdAt;
}

final class NestedWireBookDto
{
	public string $title = '';
	public NestedWireAuthorDto $author;
}
