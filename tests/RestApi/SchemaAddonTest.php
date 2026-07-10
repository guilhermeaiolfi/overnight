<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Data\Definition\Registry;
use ON\RestApi\Addon\SchemaAddon;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class SchemaAddonTest extends TestCase
{
	use RestApiTestFixtures;

	public function testSchemaIncludesValidationRulesAndMessages(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3', [
					'min' => 'Name must be at least :min characters.',
				])
				->end()
			->end();

		$addon = new SchemaAddon($registry);
		$addon->register(['basePath' => '/items']);

		$response = $addon->process(
			new ServerRequest(uri: '/items/_schema/user', method: 'GET'),
			new class () implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return new JsonResponse(['miss' => true]);
				}
			}
		);

		$response->getBody()->rewind();
		$body = json_decode((string) $response->getBody(), true);
		$nameField = null;

		foreach ($body['data']['fields'] as $field) {
			if ($field['name'] === 'name') {
				$nameField = $field;

				break;
			}
		}

		$this->assertNotNull($nameField);
		$this->assertSame('required|min:3', $nameField['validation']);
		$this->assertSame(
			['min' => 'Name must be at least :min characters.'],
			$nameField['validationMessages']
		);
	}
}
