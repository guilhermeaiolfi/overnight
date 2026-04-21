<?php

declare(strict_types=1);

namespace ON\View\Plates;

use League\Plates\Engine;
use ON\Application;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\View\RendererInterface;
use ON\View\ViewConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PlatesRenderer implements RendererInterface
{
	public function __construct(
		protected ViewConfig $config,
		protected Engine $engine,
		protected Application $app
	) {
	}

	public function render($layout, $template_name = null, $data = null, $params = [])
	{
		$engine = $this->engine;
		$request = $params['request'] ?? null;

		$template = $engine->make($template_name);

		if (isset($layout["sections"])) {
			foreach ($layout["sections"] as $section_name => $section_value) {
				$type = "text";
				$content = null;

				if (is_array($section_value)) {
					// array format: ["/layout/front/footer", "Core\Page\FooterPage::index", ["GET"], "layout.front.footer"]
					$section_value = new Route(...$section_value);
				}

				if ($section_value instanceof Route) {
					$response = $this->runSection($section_value, $request);

					$content = (string) $response->getBody();
				} else if (is_string($section_value)) {
					if (strpos($section_value, ".php") !== false) {
						ob_start();              // Start capturing output
						include $section_value;    // Execute the file
						$content = ob_get_clean();
					} else {
						$content = $section_value;
					}
				}

				$template->start($section_name);
				echo $content;
				$template->end();
			}
		}
		$template->layout($layout["name"], $data);

		return $template->render($data);
	}

	public function runSection(Route $route, ?ServerRequestInterface $parentRequest = null): ResponseInterface
	{
		$request = $this->createSectionRequest($route, $parentRequest);

		return $this->app->handle($request);
	}

	protected function createSectionRequest(Route $route, ?ServerRequestInterface $parentRequest = null): ServerRequestInterface
	{
		$result = RouteResult::fromRoute($route);

		if (! $parentRequest) {
			return $this->app->pipeline->prepareRequestFromRouteResult($result);
		}

		$request = $parentRequest->withUri(
			$parentRequest->getUri()->withPath($route->getPath())
		);

		return $this->app->pipeline->prepareRequestFromRouteResult($result, $request);
	}
}
