<?php

declare(strict_types=1);

namespace ON\FileRouting;

use Exception;
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
	}

	protected function matchInFolder(string $current_path, string $request_method, array $segments, int $index, array $params): ?array
	{
		$n = count($segments);

		$is_last = $index == $n - 1;


		/*if ($index >= count($segments)) {
			return $this->fileExists($current_path, $request_method);
		}*/

		$part = $segments[$index];

		if ($part == ".." || $part == '') {
			return null; //throw new Exception("Illigal part ('{$part}') in the URL in index {$index}");
		}

		$current_path = $current_path . DIRECTORY_SEPARATOR . $part;


		// lets see if it's in here

		if ($is_last) {
			$found = $this->fileExists($current_path, $request_method);
			if ($found) {
				//dump("return", $found, $params);
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

			//dump('dir');
			return $this->matchInFolder($current_path, $request_method, $segments, $index + 1, $params);
		} else {
			// lets go after [slug]
			// it works by trying to find the slug in the filesystem, and if found,
			// send it to the function to evaluate at the same level/index again
			$scanned_dir = dirname($current_path);
			$ls = scandir($scanned_dir);

			for ($i = 2, $x = count($ls); $i < $x; $i++) {
				if (str_contains($ls[$i], '[')) {
					// Slug folder
					if (is_dir($scanned_dir . DIRECTORY_SEPARATOR . $ls[$i])) {
						$params[substr($ls[$i], 1, -1)] = $part;
						$segments[$index] = $ls[$i];

						return $this->matchInFolder($scanned_dir, $request_method, $segments, $index, $params);

						// Slug file
					} elseif ($is_last) {
						$slug_filename_without_extension = explode('.', $ls[$i])[0];
						$params[substr($slug_filename_without_extension, 1, -1)] = $part;
						$segments[$index] = $slug_filename_without_extension;

						return $this->matchInFolder($scanned_dir, $request_method, $segments, $index, $params);
					}
				}
			}
		}

		return null;
	}

	public function match(ServerRequestInterface $request): RouteResult
	{
		$path = $request->getUri()->getPath();

		$path = str_replace($this->basePath, "", $path);

		$segments = explode('/', $path);

		array_shift($segments);

		//$depth = count($segments);

		$request_method = strtolower($request->getMethod());

		$valid_request_methods = ["get", "post", "delete", "put", "patch"];
		if (! in_array($request_method, $valid_request_methods)) {
			RouteResult::fromRouteFailure([$request_method]);
		}

		$current_path = $this->fileRoutingConfig->get('pagesPath');
		$result = $this->matchInFolder($current_path, $request_method, $segments, 0, []);

		if (isset($result)) {

			$route = new Route($path, $this->fileRoutingConfig->get('controller'), [$request_method], str_replace("/", ".", $path));
			$route_result = RouteResult::fromRoute($route, $result[1]);
			$route_result->set("_fileController", $result[0]);

			return $route_result;
		}

		return RouteResult::fromRouteFailure([$request_method]);
	}

	public function fileExists(string $path, string $method, ?string $slug_file = null, string $extension = "php"): ?string
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

		$items = [
			$path . "." . $method . "." . $extension,
			$path . "." . $extension,
			$path . DIRECTORY_SEPARATOR . "index." . $method . "." . $extension,
			$path . DIRECTORY_SEPARATOR . "index." . $extension,
		];

		foreach ($items as $item) {
			if (file_exists($item)) {
				return realpath($item);
			}
		}

		return null;
	}
}
