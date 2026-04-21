<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use ON\Container\Executor\ExecutorInterface;
use ON\Router\UrlHelper;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;

class ViewManager
{
	protected ?array $activeViewContext = null;

	public function __construct(
		protected ViewConfig $config,
		protected ContainerInterface $container
	) {
	}

	public function createView(): ViewInterface
	{
		return new View($this);
	}

	public function render(View $view, array $data, ?string $templateName = null, ?string $layoutName = null): string
	{
		$activeContext = $this->getActiveViewContext();

		if ($templateName === null) {
			$templateName = $activeContext['templateName'] ?? $view->getDefaultTemplateName();
			if ($templateName === null) {
				throw new Exception('No template name provided and no default template name set.');
			}
		}

		if ($layoutName === null) {
			$layoutName = $this->config["formats"]["html"]["default"] ?? 'default';
		}

		if (! isset($this->config["formats"]['html']['layouts'][$layoutName])) {
			throw new Exception("There is no configuration for layout name: \"{$layoutName}\"");
		}

		$layoutConfig = $this->config["formats"]['html']['layouts'][$layoutName];
		$rendererName = $layoutConfig['renderer'];
		$rendererConfig = $this->config["formats"]['html']['renderers'][$rendererName];
		$rendererClass = $rendererConfig['class'] ?? '\ON\Renderer';
		$renderer = $this->container->get($rendererClass);

		if (! empty($rendererConfig['inject'])) {
			$renderContext = new RenderContext(
				$this->container,
				$activeContext['request'] ?? null,
				$data,
				['layout' => $layoutConfig, 'template' => $templateName]
			);

			foreach ($rendererConfig['inject'] as $key => $class) {
				$data[$key] = $this->resolveInjectedValue($class, $renderContext);
			}
		}

		$layoutConfig["name"] = $layoutName;

		return $renderer->render($layoutConfig, $templateName, $data, [
			'request' => $activeContext['request'] ?? null,
		]);
	}

	public function runView(
		object $actionPage,
		string $actionName,
		mixed $response,
		ServerRequestInterface $request,
		RequestHandlerInterface $delegate,
		ExecutorInterface $executor
	): mixed {
		if ($response instanceof ResponseInterface) {
			return $response;
		}
		$response = $this->resolveViewResult($response);

		$viewPage = $actionPage;
		$viewName = $response->view;

		// TODO: better handling of that convention for specifying view page, maybe with a special syntax or something else
		if (strpos($viewName, ":") !== false) {
			$parts = explode(":", $viewName);
			$pageClass = $parts[0] . 'Page';
			$viewName = strtolower($parts[1]);

			if ($pageClass !== get_class($actionPage)) {
				$viewPage = $this->container->get($pageClass);
			}
		} else {
			$viewName = strtolower($viewName);
		}

		$templateName = null;

		$path = explode("\\", get_class($viewPage));
		$templateName = strtolower($path[0] . "::" . str_replace("Page", "", array_pop($path)) . "-" . $actionName . "-" . strtolower($viewName));

		$absoluteViewMethod = $this->resolveViewMethod($viewPage, $viewName, $actionName);
		$previousContext = $this->activeViewContext;
		$this->activeViewContext = [
			'request' => $request,
			'templateName' => $templateName,
		];

		try {
			return $executor->execute([
				$viewPage,
				$absoluteViewMethod,
			],
			$this->resolveViewParameters(
				$response,
				$request,
				$delegate
			));
		} finally {
			$this->activeViewContext = $previousContext;
		}
	}

	protected function resolveViewResult(mixed $response): mixed
	{
		if ($response instanceof ViewResult) {
			return $response;
		}

		if (is_string($response)) {
			return new ViewResult($response);
		}

		throw new Exception("Response invalid", 1);
	}

	protected function resolveViewParameters(
		ViewResult $result,
		ServerRequestInterface $request,
		RequestHandlerInterface $delegate
	): array {
		$parameters = array_merge($result->data, [
			ViewResult::class => $result,
			'result' => $result,
			'request' => $request,
			'delegate' => $delegate,
			'data' => $result->data,
		]);

		$renderContext = new RenderContext(
			$this->container,
			$request,
			$result->data,
			['result' => $result]
		);

		foreach ($this->config->get('helpers', []) as $name => $class) {
			$helper = $this->resolveInjectedValue($class, $renderContext);
			$parameters[$name] = $helper;
			$parameters[$class] ??= $helper;
		}

		return $parameters;
	}

	protected function resolveViewMethod(object $page, string $viewName, string $actionName): string
	{
		$method = strtolower($viewName) . 'View';
		if (method_exists($page, $method)) {
			return $method;
		}

		$method = $actionName . ucfirst($viewName) . 'View';
		if (method_exists($page, $method)) {
			return $method;
		}

		$method = strtolower($viewName);
		if (method_exists($page, $method)) {
			return $method;
		}

		$className = get_class($page);
		throw new Exception("No view method found for '{$viewName}' in class {$className}");
	}

	protected function resolveInjectedValue(string $class, RenderContext $context): mixed
	{
		if (is_a($class, RenderContextAwareHelperInterface::class, true)) {
			return $class::createFromRenderContext($context);
		}

		return $this->container->get($class);
	}

	protected function getActiveViewContext(): ?array
	{
		if ($this->activeViewContext === null) {
			return null;
		}

		return $this->activeViewContext;
	}
}
