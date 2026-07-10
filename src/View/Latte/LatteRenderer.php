<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Exception;
use Latte\Engine;
use ON\Application;
use ON\View\RendererInterface;
use ON\View\ViewConfig;
use Psr\Container\ContainerInterface;

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
		$mode = $params['mode'] ?? 'layout';

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

		if ($mode === 'fragment') {
			$contentPath = $this->findTemplate($template_name, $ext);

			return $engine->renderToString($contentPath, $data);
		}

		$templatePath = $this->findTemplate($layout["name"], $ext);
		$data["__sections"] = $params['sections'] ?? [];

		return $engine->renderToString($templatePath, $data);

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
}
