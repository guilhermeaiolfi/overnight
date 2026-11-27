<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Exception;
use Latte\Engine;
use Latte\Helpers;
use ON\Application;
use ON\FS\Path;
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
		$data = Helpers::resolveParams($engine, $data ?? []);

		$latte_renderer_config = $config['formats']['html']['renderers']['latte'] ?? [];

		if (isset($latte_renderer_config['inject']) && is_array($latte_renderer_config['inject'])) {
			foreach ($latte_renderer_config['inject'] as $key => $class) {
				if (array_key_exists($key, $data)) {
					continue;
				}
				$data[$key] = $this->container->get($class);
			}
		}
		$ext = $latte_renderer_config['extension'] ?? $config['latte']['extension'] ?? null;

		if ($mode === 'fragment') {
			unset($data['__sections']);
			$contentPath = $this->findTemplate($template_name, $ext);

			return $engine->renderToString($contentPath, $data);
		}

		$templatePath = $this->findTemplate($layout['name'], $ext);
		$data['__sections'] = $params['sections'] ?? [];

		return $engine->renderToString($templatePath, $data);
	}

	public function findTemplate($name, $ext = null)
	{
		if (! is_string($name) || str_contains($name, "\0") || ! str_contains($name, '::')) {
			throw new Exception('Expected a qualified Latte template name.', 1);
		}

		[$namespace, $template_path] = explode('::', $name, 2);
		$config = $this->config;
		$namespace_paths = $config['templates']['paths'][$namespace] ?? null;

		if (is_string($namespace_paths)) {
			$namespace_paths = [$namespace_paths];
		}

		if (! is_array($namespace_paths) || $namespace_paths === []) {
			throw new Exception("The template namespace ({$namespace}) is not configured", 1);
		}

		$suffix = $ext ? '.' . $ext : '';

		foreach ($namespace_paths as $path) {
			if (! is_string($path) || $path === '') {
				continue;
			}

			if (! Path::isAbsoluteString($path)) {
				$path = Path::from($path, $this->app->paths->get('project'))->getAbsolutePath();
			}
			$realBase = realpath($path);
			if ($realBase === false || ! is_dir($realBase)) {
				continue;
			}

			$candidate = $realBase . DIRECTORY_SEPARATOR . $template_path . $suffix;
			$realCandidate = realpath($candidate);
			if ($realCandidate === false || ! is_file($realCandidate)) {
				continue;
			}

			$normalizedBase = rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR;
			if (! str_starts_with($realCandidate, $normalizedBase)) {
				throw new Exception("The template ({$name}) escapes the namespace directory", 1);
			}

			return $realCandidate;
		}

		throw new Exception("The template ({$name}) doesn't exist", 1);
	}
}
