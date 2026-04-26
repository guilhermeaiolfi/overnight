<?php

declare(strict_types=1);

namespace ON\FileRouting\Page;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\FileRouting\Addon\FileRoutingAddonInterface;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingConfig;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use ON\View\ViewManager;
use ON\View\ViewResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class MainPage
{
	protected string $layout;
	protected FileRoutingCache $fileRoutingCache;

	public function __construct(
		protected ViewManager $viewManager,
		protected RouterInterface $router,
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingCfg,
		protected ?ContainerInterface $container = null
	) {
		$this->layout = $this->viewCfg->get("formats.html.default");
		$this->fileRoutingCache = new FileRoutingCache($fileRoutingCfg, $viewCfg);
	}

	public function index(ServerRequestInterface $request): mixed
	{
		$result = $request->getAttribute(RouteResult::class);
		$file = $result->get("_fileController");

		[$php_file, $template_file, $template_lang, $page_meta] = $this->fileRoutingCache->get($file);

		$page_context = [
			'request' => $request,
			'sourceFile' => $file,
			'relativeFile' => $this->fileRoutingCache->getPathFromFile($file),
			'params' => $result->getMatchedParams(),
			'metadata' => $page_meta,
		];

		$data = [
			'_pageMeta' => $page_meta,
			'_pageContext' => $page_context,
		];

		if (isset($php_file)) {
			[$return, $controller_data] = $this->includeControllerFile($php_file, $page_context, $page_meta);
			if ($return !== 1 && $return !== null) {
				return $return;
			}
			$data = array_merge($data, $controller_data);
		}

		if (! isset($data['_title']) && isset($page_meta['title']) && is_string($page_meta['title'])) {
			$data['_title'] = $page_meta['title'];
		}

		$data = $this->processAddons($page_context, $data);

		$data['_templateFileName'] = $template_file;
		$data['_templateName'] = $this->fileRoutingCache->getTemplateName($template_file);
		$data['_templateLang'] = $template_lang;

		return new ViewResult('success', $data);
	}

	protected function includeControllerFile(string $php_file, array $pageContext = [], array $pageMeta = []): array
	{
		$include = function (string $php_file, array $pageContext, array $pageMeta): array {
			$page = $this;
			$defined_vars = array_flip(array_keys(get_defined_vars()));

			ob_start();
			$return = include $php_file;
			ob_end_clean();

			$data = array_diff_key(get_defined_vars(), $defined_vars, [
				'defined_vars' => true,
				'return' => true,
			]);

			return [$return, $data];
		};

		return $include->call($this, $php_file, $pageContext, $pageMeta);
	}

	protected function processAddons(array $pageContext, array $data): array
	{
		foreach ($this->fileRoutingCfg->get('addons', []) as $addon) {
			if (is_string($addon)) {
				$addon = $this->container?->has($addon) ? $this->container->get($addon) : new $addon();
			}

			if (! $addon instanceof FileRoutingAddonInterface) {
				throw new RuntimeException(sprintf(
					'File routing addon must implement %s.',
					FileRoutingAddonInterface::class
				));
			}

			$data = $addon->process($pageContext, $data);
		}

		return $data;
	}

	protected function setLayout(string $layout): void
	{
		$this->layout = $layout;
	}

	public function successView(array $data, ServerRequestInterface $request = null, $delegate = null)
	{
		$template_file = $data['_templateFileName'] ?? '';
		$template_name = $data['_templateName'] ?? str_replace([".phtml", ".php"], "", $template_file);

		return new HtmlResponse($this->viewManager->render($data, $template_name, $this->layout));
	}
}
