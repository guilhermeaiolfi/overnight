<?php

declare(strict_types=1);

namespace ON\Container;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class ResponseFactory implements ResponseFactoryInterface
{
	public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
	{
		$response = new Response(status: $code);

		if ($reasonPhrase !== '') {
			return $response->withStatus($code, $reasonPhrase);
		}

		return $response;
	}
}
