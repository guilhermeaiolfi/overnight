<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Exception;
use function explode;
use Latte\Engine;
use ON\Application;
use ON\Router\Route;
use ON\Router\RouteResult;
use ON\View\RendererInterface;
use ON\View\ViewConfig;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LatteRenderer implements RendererInterface
{
	protected $config = null;
	protected $app = null;
	protected $engine = null;
	protected $container = null;

	public function __construct(ViewConfig $config, Engine $engine, Application $app, ContainerInterface $container)
	{
		$this->config = $config;
		$this->engine = $engine;
		$this->app = $app;
		$this->container = $container;
	}

	public function render($layout, $template_name = null, $data = null, $params = [])
	{

		$config = $this->config;

		$engine = $this->engine;

		//print_r($layout);
		$latte_renderer_config = $config["formats"]["html"]["renderers"]["latte"];

		if (isset($latte_renderer_config["inject"]) && is_array($latte_renderer_config["inject"])) {
			foreach ($latte_renderer_config["inject"] as $key => $class) {
				if (array_key_exists($key, $data)) {
					continue;
				}
				$data[$key] = $this->container->get($class);
			}
		}
		$ext = isset($latte_renderer_config["extension"]) ? $latte_renderer_config["extension"] : $config["latte"]["extension"];

		$templatePath = $this->findTemplate($layout["name"], $ext);
		$blocks = [];
		$request = $params['request'] ?? null;
		if (isset($layout["sections"])) {
			foreach ($layout["sections"] as $section_name => $section_value) {
				$type = "text";
				$content = null;

				if (is_array($section_value)) { // convert to Route instance
					//	array format: ["/layout/front/footer", "Core\Page\FooterPage::index", ["GET"], "layout.front.footer"]
					$section_value = new Route(...$section_value);
				}

				if (is_string($section_value)) {
					if (strpos($section_value, "." . $ext) !== false) {
						$type = "file";

						// findTemplate expect template without extension
						$template = substr($section_value, 0, -strlen("." . $ext));
						$content = $this->findTemplate($template, $ext);
					} else {
						$content = $section_value;
					}
				} else if ($section_value instanceof Route) {
					$response = $this->runSection($section_value, $request);
					$content = (string) $response->getBody();
				} else {
					throw new Exception("Invalid section configuration for section: {$section_name}");
				}
				
				$blocks[$section_name] = ["type" => $type, "content" => $content];
			}
		}
		$contentPath = $this->findTemplate($template_name, $ext);
		$blocks["content"] = ["type" => "text", "content" => $engine->renderToString($contentPath, $data)];
		$engine->addProvider('coreParentFinder', function ($template) use ($templatePath) {
			if (! $template->getReferenceType()) {
				return $templatePath;
			}
		});
		$data["__sections"] = $blocks;

		return $engine->renderToString($contentPath, $data);

	}

	public function findTemplate($name, $ext = null)
	{
		list($namespace, $template_path) = explode("::", $name);
		$config = $this->config;
		$fs = null;
		$namespace_paths = $config["templates"]["paths"][$namespace];
		if (is_array($namespace_paths)) {
			foreach ($namespace_paths as $index => $path) {
				$fs_path = $path . "/" . $template_path . ($ext ? "." . $ext : "");
				if (file_exists($fs_path)) {
					return $fs_path;
				}
			}
		} elseif (is_string($namespace_paths)) {
			$fs_path = $namespace_paths . "/" . $template_path . ($ext ? "." . $ext : "");
			if (file_exists($fs_path)) {
				return $fs_path;
			}
		}

		throw new Exception("The template filename({$fs_path}) doesn't exist", 1);

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
