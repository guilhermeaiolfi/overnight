<?php

declare(strict_types=1);

namespace ON\FileRouting\Page;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\AbstractPage;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingConfig;
use ON\Router\RouteResult;
use ON\Router\RouterInterface;
use ON\View\ViewConfig;
use Psr\Http\Message\ServerRequestInterface;

class MainPage extends AbstractPage
{
	protected string $layout;
	protected FileRoutingCache $fileRoutingCache;

	public function __construct(
		protected RouterInterface $router,
		protected ViewConfig $viewCfg,
		protected FileRoutingConfig $fileRoutingCfg
	) {
		$this->layout = $this->viewCfg->get("formats.html.default");
		$this->fileRoutingCache = new FileRoutingCache($fileRoutingCfg);
	}

	public function index(ServerRequestInterface $request)
	{

		$result = $request->getAttribute(RouteResult::class);
		$file = $result->get("_fileController");

		$page = $this;

		[$php_file, $template_file] = $this->fileRoutingCache->get($file);

		$this->setAttribute("_templateFileName", $template_file);

		if (isset($php_file)) {
			$return = include_once($php_file);
			if (isset($return)) {
				return $return;
			}
		}

		return 'Success';
	}

	protected function setLayout(string $layout): void
	{
		$this->layout = $layout;
	}

	public function successView(ServerRequestInterface $request)
	{

		/*$template_name = $this->getTemplateFileFromRouteResult($request->getAttribute(RouteResult::class));
		$template_path = $this->viewCfg->get("templates.paths.fileRouting")[0];
		$template_name = str_replace([$template_path, ".phtml"], ["static::", ""], $template_name);*/

		$template_file = $this->getAttribute("_templateFileName");

		return new HtmlResponse($this->render($this->layout, str_replace(".phtml", "", $template_file)));
	}

	protected function getPhpFileFromRequest($request): ?string
	{
		$path = $request->getUri()->getPath();
		$path = str_replace($this->router->getBasePath(), "", $path);

		$possibilities = [
			"src/Static/pages" . $path . ".php",
			"src/Static/pages" . $path . "/index.php",
		];

		foreach ($possibilities as $file) {
			if (file_exists($file)) {
				return $file;
			}
		}

		return null;
	}

	protected function getTemplateFileFromRouteResult(RouteResult $result): ?string
	{
		$controller_file = $result->get("_fileController");

		return str_replace(".php", ".phtml", $controller_file);
	}
}
