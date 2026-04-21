<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use ON\Container\Executor\ExecutorInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ViewManager
{
	protected ?ViewResult $activeViewResult = null;

	public function __construct(
		protected ViewConfig $config,
		protected ContainerInterface $container
	) {
	}

	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string
	{
		$viewResult = $this->getActiveViewResult();

		if ($templateName === null) {
			$templateName = $viewResult?->getTemplateName();
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
				$viewResult?->getRequest() ?? null,
				$data,
				['layout' => $layoutConfig, 'template' => $templateName]
			);

			foreach ($rendererConfig['inject'] as $key => $class) {
				$data[$key] = $this->resolveInjectedValue($class, $renderContext);
			}
		}

		$layoutConfig["name"] = $layoutName;

		return $renderer->render($layoutConfig, $templateName, $data, [
			'request' => $viewResult?->getRequest() ?? null,
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
		$result = $this->resolveViewResult($response);

		$result->setActionName($actionName);
		
		if ($result->getPageClass() === null) {
			$result->setTargetObject($actionPage);
		} else {
			$targetObject = $this->container->get($result->getPageClass());
			$result->setTargetObject($targetObject);
		}

		$result->setRequest($request);

		$previousViewResult = $this->activeViewResult;
		$this->activeViewResult = $result;

		try {
			return $executor->execute([
				$result->getTargetObject(),
				$result->getViewMethod(),
			],
			$this->resolveViewParameters(
				$result,
				$request,
				$delegate
			));
		} finally {
			$this->activeViewResult = $previousViewResult;
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

		$data = $result->toArray();
		$parameters = array_merge($data, [
			ViewResult::class => $result,
			'request' => $request,
			'delegate' => $delegate,
			'data' => $data,
		]);

		$renderContext = new RenderContext(
			$this->container,
			$request,
			$data,
			[ "resultView" => $result ]
		);

		foreach ($this->config->get('helpers', []) as $name => $class) {
			$helper = $this->resolveInjectedValue($class, $renderContext);
			//$parameters[$name] = $helper;
			$parameters[$class] ??= $helper;
		}

		return $parameters;
	}

	protected function resolveInjectedValue(string $class, RenderContext $context): mixed
	{
		if (is_a($class, RenderContextAwareHelperInterface::class, true)) {
			return $class::createFromRenderContext($context);
		}

		return $this->container->get($class);
	}

	protected function getActiveViewResult(): ?ViewResult
	{
		if ($this->activeViewResult === null) {
			return null;
		}

		return $this->activeViewResult;
	}
}
