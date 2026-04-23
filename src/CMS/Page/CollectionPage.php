<?php

declare(strict_types=1);

namespace ON\CMS\Page;

use Laminas\Diactoros\Response\JsonResponse;
use ON\DB\DatabaseConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CollectionPage
{
	public function __construct(
		protected DatabaseConfig $dbCfg
	) {

	}

	public function create(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}

	public function update(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}

	public function all(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}

	public function getOne(ServerRequestInterface $request): ResponseInterface
	{
		$id = $request->getAttribute("id");

		return new JsonResponse([ "id" => $id ]);
	}

	public function remove(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}
}
