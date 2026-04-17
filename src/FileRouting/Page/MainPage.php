<?php

declare(strict_types=1);

namespace ON\FileRouting\Page;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingConfig;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use ON\View\ViewInterface;
use ON\View\ViewResult;
use Psr\Http\Message\ServerRequestInterface;

class MainPage
{
	protected string $layout;
	protected FileRoutingCache $fileRoutingCache;

	public function __construct(
		public ViewInterface $view,
		protected RouterInterface $router,
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingCfg
	) {
		$this->layout = $this->viewCfg->get("formats.html.default");
		$this->fileRoutingCache = new FileRoutingCache($fileRoutingCfg);
	}

	public function index(ServerRequestInterface $request): mixed
	{
		$result = $request->getAttribute(RouteResult::class);
		$file = $result->get("_fileController");

		$page = $this;

		[$php_file, $template_file] = $this->fileRoutingCache->get($file);

		if (isset($php_file)) {
			$return = include_once($php_file);
			if (isset($return)) {
				return $return;
			}
		}

		return new ViewResult('success', ['_templateFileName' => $template_file]);
	}

	protected function setLayout(string $layout): void
	{
		$this->layout = $layout;
	}

	public function successView(array $data, ServerRequestInterface $request = null, $delegate = null)
	{
		$template_file = $data['_templateFileName'] ?? '';

		return new HtmlResponse($this->view->render($data, str_replace(".phtml", "", $template_file), $this->layout));
	}
}
