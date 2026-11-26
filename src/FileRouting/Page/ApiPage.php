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

	public function index(ServerRequestInterface $request): JsonResponse
	{
		$pagesPath = $this->fileRoutingCfg->get('pagesPath');
		$realBase = is_string($pagesPath) && $pagesPath !== '' ? realpath($pagesPath) : false;
		if ($realBase === false || ! is_dir($realBase)) {
			return new JsonResponse([
				'error' => 'Pages directory not found.',
			], 404);
		}

		$params = $request->getQueryParams();
		$rawLocation = $params['location'] ?? '';

		if (! is_string($rawLocation)) {
			return new JsonResponse([
				'error' => 'Invalid location parameter.',
			], 400);
		}

		if ($rawLocation !== '' && $this->isTraversalAttempt($rawLocation)) {
			return new JsonResponse([
				'error' => 'Forbidden location.',
			], 403);
		}

		$location = trim($rawLocation, " \t\n\r\v");

		if ($location !== '') {
			if ($this->isTraversalAttempt($location)) {
				return new JsonResponse([
					'error' => 'Forbidden location.',
				], 403);
			}

			$trimmedLocation = rtrim($location, '/');
			$segments = explode('/', $trimmedLocation);
			foreach ($segments as $segment) {
				if ($segment === '' || $segment === '.' || $segment === '..') {
					return new JsonResponse([
						'error' => 'Forbidden location.',
					], 403);
				}
			}

			$candidate = $realBase . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
			$realTarget = realpath($candidate);

			if ($realTarget === false) {
				return new JsonResponse([
					'error' => 'Location not found.',
				], 404);
			}

			if (! $this->isPathWithinBase($realTarget, $realBase)) {
				return new JsonResponse([
					'error' => 'Forbidden location.',
				], 403);
			}

			if (! is_dir($realTarget)) {
				return new JsonResponse([
					'error' => 'Location is not a directory.',
				], 400);
			}
		} else {
			$realTarget = $realBase;
		}

		$finder = new Finder();
		$finder->in($realTarget);

		$normalizedBase = rtrim($realBase, DIRECTORY_SEPARATOR);
		$files = [];
		foreach ($finder as $file) {
			$realFilePath = realpath($file->getPathname());
			if ($realFilePath === false || ! $this->isPathWithinBase($realFilePath, $realBase)) {
				continue;
			}

			$relativePath = ltrim(substr($realFilePath, strlen($normalizedBase)), DIRECTORY_SEPARATOR);
			$relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

			$files[] = [
				'path' => $relativePath,
				'filename' => $file->getFilename(),
				'extension' => $file->getExtension(),
				'size' => $file->getSize(),
				'is_dir' => $file->isDir(),
				'is_file' => $file->isFile(),
			];
		}

		return new JsonResponse([
			'result' => $files,
		], 200);
	}

	protected function isTraversalAttempt(string $location): bool
	{
		if (
			str_contains($location, "\0")
			|| str_contains($location, '\\')
			|| str_starts_with($location, '/')
			|| preg_match('/^[a-zA-Z]:/', $location) === 1
		) {
			return true;
		}

		$decoded = rawurldecode($location);
		if (
			str_contains($decoded, "\0")
			|| str_contains($decoded, '\\')
			|| str_starts_with($decoded, '/')
			|| preg_match('/^[a-zA-Z]:/', $decoded) === 1
		) {
			return true;
		}

		foreach (explode('/', rtrim($decoded, '/')) as $segment) {
			if ($segment === '.' || $segment === '..') {
				return true;
			}
		}

		return false;
	}

	protected function isPathWithinBase(string $realTarget, string $realBase): bool
	{
		$normalizedBase = rtrim($realBase, DIRECTORY_SEPARATOR);
		if ($realTarget === $normalizedBase) {
			return true;
		}

		return str_starts_with($realTarget, $normalizedBase . DIRECTORY_SEPARATOR);
	}
}
