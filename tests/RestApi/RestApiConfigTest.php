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
		$this->assertSame('auto', $config->get('resolver'));
	}
}
