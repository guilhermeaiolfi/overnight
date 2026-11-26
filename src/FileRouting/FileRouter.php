<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Router\Route;
use ON\Router\Router;
use ON\Router\RouteResult;
use Psr\Http\Message\ServerRequestInterface;

class FileRouter
{
	public function __construct(
		protected FileRoutingConfig $fileRoutingConfig,
		protected ?string $basePath = null
	) {
		if ($this->basePath === null) {
			$this->basePath = Router::detectBaseUrl();
		}

		$this->basePath = Router::normalizePath($this->basePath);
		if ($this->basePath !== '' && ! str_starts_with($this->basePath, '/')) {
			$this->basePath = '/' . $this->basePath;
		}
	}

	protected function isSafeSegment(string $segment): bool
	{
		if ($segment === '' || $segment === '.' || $segment === '..') {
			return false;
		}

		if (str_contains($segment, "\0") || str_contains($segment, '\\') || str_contains($segment, '/')) {
			return false;
		}

		$decoded = rawurldecode($segment);
		if ($decoded === '' || $decoded === '.' || $decoded === '..') {
			return false;
		}

		if (str_contains($decoded, "\0") || str_contains($decoded, '\\') || str_contains($decoded, '/')) {
			return false;
		}

		return true;
	}

	protected function matchInFolder(string $current_path, string $request_method, array $segments, int $index, array $params): ?array
	{
		$n = count($segments);

		$is_last = $index == $n - 1;

		$part = $segments[$index];

		if (! $this->isSafeSegment($part)) {
			return null;
		}

		$current_path = $current_path . DIRECTORY_SEPARATOR . $part;

		// lets see if it's in here

		if ($is_last) {
			$found = $this->fileExists($current_path, $request_method);
			if ($found) {
				return [
					$found,
					$params,
				];
			}
		}

		if (is_dir($current_path)) {
			if ($is_last) {
				return null;
			}

			return $this->matchInFolder($current_path, $request_method, $segments, $index + 1, $params);
		} else {
			// lets go after [slug]
			// it works by trying to find the slug in the filesystem, and if found,
			// send it to the function to evaluate at the same level/index again
			$scanned_dir = dirname($current_path);
			if (! is_dir($scanned_dir)) {
				return null;
			}

			$ls = scandir($scanned_dir);
			if ($ls === false) {
				return null;
			}

			foreach ($ls as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				if (str_contains($entry, '[')) {
					// Slug folder
					if (is_dir($scanned_dir . DIRECTORY_SEPARATOR . $entry)) {
						if ($entry === $part) {
							continue;
						}

						$nextParams = $params;
						$nextParams[substr($entry, 1, -1)] = $part;
						$nextSegments = $segments;
						$nextSegments[$index] = $entry;

						$matched = $this->matchInFolder($scanned_dir, $request_method, $nextSegments, $index, $nextParams);
						if ($matched !== null) {
							return $matched;
						}

						// Slug file
					} elseif ($is_last) {
						$slug_filename_without_extension = explode('.', $entry)[0];
						if ($slug_filename_without_extension === $part) {
							continue;
						}

						$nextParams = $params;
						$nextParams[substr($slug_filename_without_extension, 1, -1)] = $part;
						$nextSegments = $segments;
						$nextSegments[$index] = $slug_filename_without_extension;

						$matched = $this->matchInFolder($scanned_dir, $request_method, $nextSegments, $index, $nextParams);
						if ($matched !== null) {
							return $matched;
						}
					}
				}
			}
		}

		return null;
	}

	public function match(ServerRequestInterface $request): RouteResult
	{
		$path = $request->getUri()->getPath();

		if ($this->basePath !== '') {
			if ($path === $this->basePath) {
				$path = '/';
			} elseif (str_starts_with($path, $this->basePath . '/')) {
				$path = substr($path, strlen($this->basePath));
			}
		}

		$segments = explode('/', $path);

		array_shift($segments);

		$request_method = strtolower($request->getMethod());

		$valid_request_methods = ['get', 'post', 'delete', 'put', 'patch'];
		if (! in_array($request_method, $valid_request_methods, true)) {
			return RouteResult::fromRouteFailure([$request_method]);
		}

		$current_path = $this->fileRoutingConfig->get('pagesPath');

		if ($segments === [] || $segments === ['']) {
			$found = $this->fileExists($current_path, $request_method);

			if ($found) {
				$route = new Route('/', $this->fileRoutingConfig->get('controller'), [$request_method], '.');
				$route_result = RouteResult::fromRoute($route, []);
				$route_result->set('_fileController', $found);

				return $route_result;
			}

			return RouteResult::fromRouteFailure([$request->getMethod()]);
		}

		$result = $this->matchInFolder($current_path, $request_method, $segments, 0, []);

		if (isset($result)) {
			$route = new Route($path, $this->fileRoutingConfig->get('controller'), [$request_method], str_replace('/', '.', $path));
			$route_result = RouteResult::fromRoute($route, $result[1]);
			$route_result->set('_fileController', $result[0]);

			return $route_result;
		}

		return RouteResult::fromRouteFailure([$request_method]);
	}

	public function fileExists(string $path, string $method, ?string $slug_file = null, string $extension = 'php'): ?string
	{
		/**
		 * for /about it could be:
		 * [
		 *      [ /, about.get.php ]
		 *      [ /, about.php ]
		 *      [ /about, index.get.php ]
		 *      [ /about, index.php ]
		 * ]
		 */
		$pagesPath = $this->fileRoutingConfig->get('pagesPath');
		$realPagesPath = is_string($pagesPath) && $pagesPath !== '' ? realpath($pagesPath) : false;
		if ($realPagesPath === false) {
			return null;
		}

		$realPagesPrefix = rtrim($realPagesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		$items = [
			$path . '.' . $method . '.' . $extension,
			$path . '.' . $extension,
			$path . DIRECTORY_SEPARATOR . 'index.' . $method . '.' . $extension,
			$path . DIRECTORY_SEPARATOR . 'index.' . $extension,
		];

		foreach ($items as $item) {
			if (file_exists($item)) {
				$realItem = realpath($item);
				if ($realItem !== false && is_file($realItem) && str_starts_with($realItem, $realPagesPrefix)) {
					return $realItem;
				}
			}
		}

		return null;
	}
}
