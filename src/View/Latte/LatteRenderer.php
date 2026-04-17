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
				$data[$key] = $this->container->get($class);
			}
		}
		$ext = isset($latte_renderer_config["extension"]) ? $latte_renderer_config["extension"] : $config["latte"]["extension"];

		$templatePath = $this->findTemplate($layout["name"], $ext);
		$sections = [];
		$blocks = [];
		$loader = null;
		$templates = null;
		if (isset($layout["sections"])) {
			foreach ($layout["sections"] as $section_name => $section_config) {
				if ($section_config instanceof Route) {
					$response = $this->runSectionFromRoute($section_config);

					// create section
					$blocks[$section_name] = ["type" => "text", "content" => $response->getBody()];

				} elseif (is_array($section_config)) { // run action
					$response = $this->runSection(...$section_config);

					// create section
					if (isset($section_config[4]) && ($options = $section_config[4]) && $options["compile"]) { // not supported yet
						$blocks[$section_name] = ["type" => "latte", "content" => $response->getBody()];
					} else {
						$blocks[$section_name] = ["type" => "text", "content" => $response->getBody()];
					}
				} elseif (is_string($section_config) && strpos($section_config, "." . $ext) !== false) { // file
					$blocks[$section_name] = ["type" => "file", "content" => $this->findTemplate($section_config)];
				} else {
					// create section
					$blocks[$section_name] = ["type" => "text", "content" => $section_config];
				}
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

	/*
	$section_config example: ["/layout/front/footer", "Core\Page\FooterPage::index", ["GET"], "layout.front.footer"]
	*/
	public function runSection($section_path, $controller, $methods, $route_name, $options = null)
	{
		$route = new Route($section_path, $controller, $methods, $route_name);
		$request = $this->app->pipeline->prepareRequestFromRouteResult(RouteResult::fromRoute($route));

		return $this->app->handle($request);
	}

	public function runSectionFromRoute(Route $route): ResponseInterface
	{
		$request = $this->app->pipeline->prepareRequestFromRouteResult(RouteResult::fromRoute($route));

		return $this->app->handle($request);
	}
}
