<?php

declare(strict_types=1);

namespace ON\FileRouting\Page;

use Laminas\Diactoros\Response\JsonResponse;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingConfig;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Finder\Finder;

class ApiPage
{
	protected FileRoutingCache $fileRoutingCache;

	public function __construct(
		protected RouterInterface $router,
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingCfg
	) {

	}

	public function index(ServerRequestInterface $request)
	{

		$finder = new Finder();

		$params = $request->getQueryParams();

		$location = $params["location"] ?? "*";

		$path = $this->fileRoutingCfg->get("pagesPath");

		$finder->in($path . DIRECTORY_SEPARATOR . $location);

		$files = [];
		foreach ($finder as $file) {
			$files[] = [
				'path' => $file->getPathname(),
				'filename' => $file->getFilename(),
				'extension' => $file->getExtension(),
				'size' => $file->getSize(),
				'is_dir' => $file->isDir(),
				'is_file' => $file->isFile(),
			];
		}

		return new JsonResponse([
			"result" => $files,
		], 200);
	}
}
