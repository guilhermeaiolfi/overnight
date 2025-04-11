<?php

declare(strict_types=1);

namespace ON\CMS\Page;

use Cycle\ORM\Select;
use Laminas\Diactoros\Response\JsonResponse;
use ON\CMS\DataHandler;
use ON\DB\DatabaseConfig;
use ON\DB\Manager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ItemsPage
{
	public function __construct(
		protected DatabaseConfig $dbCfg,
		protected Manager $db,
		protected DataHandler $dataHandler
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
		$query = $request->getQueryParams();
		$collection = $request->getAttribute("collection");
		$select = $this->dataHandler->getSelectQuery($collection, $query);

		/** @var Select $select */

		$all = $select->fetchData();
		//dd($all);

		//exit;

		return new JsonResponse($all);
	}

	public function getOne(ServerRequestInterface $request): ResponseInterface
	{
		$id = $request->getAttribute("id");
		echo "getOne";

		return new JsonResponse([ "id" => $id ]);
	}

	public function remove(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([]);
	}
}
