<?php

declare(strict_types=1);

namespace Tests\ON\Fixtures;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

class TestPage
{
	public array $testData = [];

	public function testIt(int $id, string $name)
	{
		$this->testData['testIt'] = ['id' => $id, 'name' => $name];
		return new JsonResponse(['method' => 'testIt', 'id' => $id, 'name' => $name]);
	}

	public function testInt(int $id)
	{
		$this->testData['testInt'] = ['id' => $id, 'type' => gettype($id)];
		return new JsonResponse(['id' => $id]);
	}

	public function testFloat(float $price)
	{
		$this->testData['testFloat'] = ['price' => $price, 'type' => gettype($price)];
		return new JsonResponse(['price' => $price]);
	}

	public function testBool(bool $active)
	{
		$this->testData['testBool'] = ['active' => $active, 'type' => gettype($active)];
		return new JsonResponse(['active' => $active]);
	}

	public function testItNoParams()
	{
		return new JsonResponse([]);
	}

	public function testItWithServerRequest(ServerRequestInterface $request)
	{
		$this->testData['testItWithServerRequest'] = ['request' => $request];
		return new JsonResponse(['received' => true]);
	}

	public function testItWithBoth(int $id, ServerRequestInterface $request)
	{
		$this->testData['testItWithBoth'] = [
			'id' => $id,
			'request' => $request,
		];
		return new JsonResponse(['id' => $id]);
	}

	public function testItUntyped($id)
	{
		$this->testData['testItUntyped'] = ['id' => $id, 'type' => gettype($id)];
		return new JsonResponse(['id' => $id]);
	}

	public function testItOptionalParam(int $id = null)
	{
		$this->testData['testItOptionalParam'] = ['id' => $id];
		return new JsonResponse(['id' => $id]);
	}

	public function resetTestData(): void
	{
		$this->testData = [];
	}
}
