<?php

declare(strict_types=1);

namespace Tests\ON\DataIntegration;

use Laminas\Diactoros\ServerRequest;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapping;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\DataIntegration\Mapper\PsrRequestMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

use function ON\Data\Mapper\map;

final class PsrRequestMapperTest extends TestCase
{
	private ConversionGateway $gateway;

	protected function setUp(): void
	{
		$this->gateway = ConversionGateway::createDefault();
		$this->gateway->getMapperManager()->prepend(PsrRequestMapper::class);
		Mapping::setDefaultGateway($this->gateway);
	}

	protected function tearDown(): void
	{
		Mapping::resetDefaultGateway();
	}

	public function testMapsQueryAndParsedBodyOntoStdClass(): void
	{
		$request = (new ServerRequest())
			->withQueryParams(['q' => 'search'])
			->withParsedBody(['title' => 'Hello']);

		$result = map($request)
			->from(WireRepresentation::class)
			->to(stdClass::class);

		$this->assertInstanceOf(stdClass::class, $result);
		$this->assertSame('search', $result->q);
		$this->assertSame('Hello', $result->title);
	}

	public function testExpandsDottedKeysFromRequestPayload(): void
	{
		$request = (new ServerRequest())
			->withParsedBody([
				'author.name' => 'Ada',
				'author.email' => 'ada@example.com',
			]);

		$result = map($request)
			->from(WireRepresentation::class)
			->to([]);

		$this->assertSame([
			'author' => [
				'name' => 'Ada',
				'email' => 'ada@example.com',
			],
		], $result);
	}

	public function testParsedBodyOverridesQueryOnKeyCollision(): void
	{
		$request = (new ServerRequest())
			->withQueryParams(['status' => 'query'])
			->withParsedBody(['status' => 'body']);

		$result = map($request)
			->from(WireRepresentation::class)
			->to([]);

		$this->assertSame(['status' => 'body'], $result);
	}

	public function testCanMapRecognizesServerRequest(): void
	{
		$request = new ServerRequest();
		$options = new \ON\Data\Mapper\MappingOptions($this->gateway);

		$this->assertTrue(PsrRequestMapper::canMap($request, $options));
		$this->assertFalse(PsrRequestMapper::canMap(['a' => 1], $options));
	}
}
