<?php

declare(strict_types=1);

namespace ON\Router;

use Exception;
use InvalidArgumentException;
use Laminas\Diactoros\ServerRequest;
use ON\View\RenderContext;
use ON\View\RenderContextAwareHelperInterface;
use Psr\Http\Message\ServerRequestInterface;

class UrlHelper implements RenderContextAwareHelperInterface
{
	public function __construct(
		protected RouterInterface $router,
		protected ServerRequestInterface $request
	) {
	}

	public static function createFromRenderContext(RenderContext $context): self
	{
		if (! $context->request) {
			throw new Exception('UrlHelper requires a current request.');
		}

		if (! $context->container->has(RouterInterface::class)) {
			throw new Exception('UrlHelper requires ' . RouterInterface::class . ' in the container.');
		}

		return new self(
			$context->container->get(RouterInterface::class),
			$context->request
		);
	}

	public function gen($routeName = null, $routeParams = [], $options = []): string
	{
		$defaultOptions = [
			"relative" => true,
			"fragment" => null,
			"absolute" => false,
			"port" => 80,
			"scheme" => "http",
		];

		$options = array_merge($defaultOptions, $options);

		$result = $this->request->getAttribute(RouteResult::class);

		if ($routeName === null) {
			if (! $result || $result->isFailure()) {
				throw new Exception(
					'Attempting to use matched result when routing failed or none was injected; aborting'
				);
			}

			$params = array_merge(
				$this->request->getQueryParams(),
				$result->getMatchedParams(),
				$routeParams
			);

			return $this->gen($result->getMatchedRouteName(), $params, $options);
		}

		$routerOptions = array_key_exists('router', $options) ? $options['router'] : [];
		$params = [];

		try {
			$path = $this->router->generateUri($routeName, $routeParams, $routerOptions);
			$result = $this->router->match(new ServerRequest([], [], $path));
			$params = $result->getMatchedParams();
		} catch (Exception $e) {
			$path = $routeName;
		}

		$uri = $this->request->getUri()->withPath($this->router->getBasePath() . "/" . ltrim($path, "\\/"));

		$queryParams = array_diff_key($routeParams, $params);
		if (count($queryParams) > 0) {
			$uri = $uri->withQuery(http_build_query($queryParams));
		} else {
			$uri = $uri->withQuery('');
		}

		if ($options["fragment"] !== null) {
			if (! preg_match(Router::FRAGMENT_IDENTIFIER_REGEX, $options["fragment"])) {
				throw new InvalidArgumentException('Fragment identifier must conform to RFC 3986', 400);
			}
			$uri = $uri->withFragment($options["fragment"]);
		}

		if (! $options["absolute"]) {
			$uri = $uri->withHost("");
		}

		if ($options["scheme"] != "http") {
			$uri = $uri->withScheme($options["scheme"]);
		} elseif ($options["absolute"]) {
			$uri = $uri->withScheme('http');
		} else {
			$uri = $uri->withScheme('');
		}

		if ($options["port"] != 80) {
			$uri = $uri->withPort($options["port"]);
		} else {
			$uri = $uri->withPort(80);
		}

		return (string) $uri;
	}
}
