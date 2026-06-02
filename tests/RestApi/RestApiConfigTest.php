<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\RestApi\RestApiConfig;
use PHPUnit\Framework\TestCase;

final class RestApiConfigTest extends TestCase
{
	public function testDefaultsUseEndpointUri(): void
	{
		$config = new RestApiConfig();

		$this->assertSame('/items', $config->get('endpointUri'));
		$this->assertSame(100, $config->get('defaultLimit'));
		$this->assertSame('directus_files', $config->get('filesCollection'));
		$this->assertSame([], $config->get('validationMessages'));
		$this->assertSame('en', $config->get('validationLang'));
		$this->assertFalse($config->hasActions());
	}

	public function testAddsSerializableActionDefinitions(): void
	{
		$config = new RestApiConfig();

		$config->addAction('get', 'GET', '{collection}/{id}', ExampleAction::class);

		$this->assertTrue($config->hasActions());
		$this->assertSame([
			[
				'name' => 'get',
				'methods' => ['GET'],
				'path' => '{collection}/{id}',
				'action' => ExampleAction::class,
				'options' => [],
			],
		], $config->get('actions'));
	}
}

final class ExampleAction
{
}
