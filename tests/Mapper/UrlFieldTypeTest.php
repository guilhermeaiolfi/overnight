<?php

declare(strict_types=1);

namespace Tests\ON\Mapper;

use ON\Data\Definition\Registry;
use ON\Mapper\ConversionGateway;
use ON\Mapper\Exception\ConversionException;
use ON\Mapper\Field\FieldContext;
use function ON\Mapper\map;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use PHPUnit\Framework\TestCase;

final class UrlFieldTypeTest extends TestCase
{
	public function testRelativeFilePathBecomesSiteAbsolutePath(): void
	{
		$result = map(['url' => ' files/docs/report.pdf '])
			->using(CollectionRowMapper::class, $this->collection())
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame('/files/docs/report.pdf', $result['url']);
	}

	public function testAbsoluteHttpsUrlRemainsUnchanged(): void
	{
		$result = map(['url' => 'https://example.com/report.pdf'])
			->using(CollectionRowMapper::class, $this->collection())
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();

		$this->assertSame('https://example.com/report.pdf', $result['url']);
	}

	public function testUnsafeSchemeIsRejected(): void
	{
		$this->expectException(ConversionException::class);

		map(['url' => 'javascript:alert(1)'])
			->using(CollectionRowMapper::class, $this->collection())
			->from(PhpRepresentation::class)
			->as(StorageRepresentation::class)
			->toArray();
	}

	public function testEmptyNullableValueBecomesNull(): void
	{
		$value = ConversionGateway::createDefault()->to(
			PhpRepresentation::class,
			' ',
			StorageRepresentation::class,
			FieldContext::named('url', 'url', true),
		);

		$this->assertNull($value);
	}

	private function collection()
	{
		$registry = new Registry();
		$registry->collection('document')
			->field('url', 'url')->type('url')->nullable(true)->end()
			->end();

		return $registry->getCollection('document');
	}
}
