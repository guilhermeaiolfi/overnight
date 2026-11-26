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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class MainPage
{
	protected string $layout;
	protected ?string $activeLayout = null;
	protected FileRoutingCache $fileRoutingCache;

	public function __construct(
		protected ViewManager $viewManager,
		protected RouterInterface $router,
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingCfg,
		protected ContainerInterface $container
	) {
		$this->layout = $this->viewCfg->get("formats.html.default");
		$this->fileRoutingCache = new FileRoutingCache($fileRoutingCfg, $viewCfg);
	}

	public function index(ServerRequestInterface $request): mixed
	{
		$routeResult = $request->getAttribute(RouteResult::class);
		$file = $routeResult instanceof RouteResult ? $routeResult->get('_fileController') : null;

		if (! is_string($file) || $file === '') {
			throw new RuntimeException('No file route match is available for the current request.');
		}

		[$php_file, $template_file, $template_lang, $page_meta] = $this->fileRoutingCache->get($file);

		$page_context = [
			'request' => $request,
			'sourceFile' => $file,
			'relativeFile' => $this->fileRoutingCache->getPathFromFile($file),
			'params' => $routeResult instanceof RouteResult ? $routeResult->getMatchedParams() : [],
			'metadata' => $page_meta,
		];

		$data = [
			'_pageMeta' => $page_meta,
			'_pageContext' => $page_context,
		];

		$previousLayout = $this->layout;
		$this->activeLayout = null;

		try {
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
			$data['_templateName'] = $this->fileRoutingCache->getTemplateName($template_file, true);
			$data['_templateLang'] = $template_lang;

			$resolvedLayout = $this->activeLayout ?? $this->layout;
			if ($this->activeLayout === null && $this->layout === $previousLayout && is_string($data['_layout'] ?? null) && $data['_layout'] !== '') {
				$resolvedLayout = $data['_layout'];
			}
			$data['_layout'] = $resolvedLayout;

			return ViewResult::for('success', $data);
		} finally {
			$this->activeLayout = null;
			$this->layout = $previousLayout;
		}
	}

	protected function includeControllerFile(string $php_file, array $pageContext = [], array $pageMeta = []): array
	{
		$obLevel = ob_get_level();
		$include = function (string $php_file, array $pageContext, array $pageMeta): array {
			$page = $this;
			$defined_vars = array_flip(array_keys(get_defined_vars()));

			$return = include $php_file;

			$data = array_diff_key(get_defined_vars(), $defined_vars, [
				'defined_vars' => true,
				'return' => true,
			]);

			return [$return, $data];
		};

		ob_start();

		try {
			return $include->call($this, $php_file, $pageContext, $pageMeta);
		} finally {
			while (ob_get_level() > $obLevel) {
				ob_end_clean();
			}
		}
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
		$this->activeLayout = $layout;
	}

	public function successView(array $data): ResponseInterface
	{
		$template_file = $data['_templateFileName'] ?? '';
		$template_name = $data['_templateName']
			?? str_replace([".phtml", ".php"], "", $template_file);
		$layout = is_string($data['_layout'] ?? null) && $data['_layout'] !== ''
			? $data['_layout']
			: $this->layout;

		return new HtmlResponse($this->viewManager->render($data, $template_name, $layout));
	}

	public function getContainer(): ?ContainerInterface
	{
		return $this->container;
	}
}
