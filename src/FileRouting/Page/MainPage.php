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

		[$php_file, $template_file] = $this->fileRoutingCache->get($file);

		$data = [];

		if (isset($php_file)) {
			[$return, $data] = $this->includeControllerFile($php_file);
			if ($return !== 1 && $return !== null) {
				return $return;
			}
		}

		$data['_templateFileName'] = $template_file;
		$data['_templateName'] = $this->fileRoutingCache->getTemplateName($template_file);

		return new ViewResult('success', $data);
	}

	protected function includeControllerFile(string $php_file): array
	{
		$include = function (string $php_file): array {
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

		return $include->call($this, $php_file);
	}

	protected function setLayout(string $layout): void
	{
		$this->layout = $layout;
	}

	public function successView(array $data, ServerRequestInterface $request = null, $delegate = null)
	{
		$template_file = $data['_templateFileName'] ?? '';
		$template_name = $data['_templateName'] ?? str_replace([".phtml", ".php"], "", $template_file);

		return new HtmlResponse($this->view->render($data, $template_name, $this->layout));
	}
}
