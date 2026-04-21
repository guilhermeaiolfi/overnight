<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use ON\Container\Executor\ExecutorInterface;
use ON\Http\RequestContext;
use ON\RequestStackInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ViewManager
{
	public function __construct(
		protected ViewConfig $config,
		protected ContainerInterface $container,
		protected RequestStackInterface $requestStack
	) {
	}

	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string
	{
		$request = $this->requestStack->getCurrentRequest();
		$viewResult = $this->getCurrentViewResult();

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
				$request,
				$data,
				['layout' => $layoutConfig, 'template' => $templateName]
			);

			foreach ($rendererConfig['inject'] as $key => $class) {
				$data[$key] = $this->resolveInjectedValue($class, $renderContext);
			}
		}

		$layoutConfig["name"] = $layoutName;

		return $renderer->render($layoutConfig, $templateName, $data, [
			'request' => $request,
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

		return $this->requestStack->usingRequest($request, function (ServerRequestInterface $request) use (
			$result,
			$delegate,
			$executor
		): mixed {
			$requestContext = $request->getAttribute(RequestContext::class);
			if ($requestContext instanceof RequestContext) {
				$requestContext->set(ViewResult::class, $result);
			}

			return $executor->execute([
				$result->getTargetObject(),
				$result->getViewMethod(),
			],
			$this->resolveViewParameters(
				$result,
				$request,
				$delegate
			));
		});
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

	protected function getCurrentViewResult(): ?ViewResult
	{
		$requestContext = $this->requestStack
			->getCurrentRequest()
			?->getAttribute(RequestContext::class);

		if (! $requestContext instanceof RequestContext) {
			return null;
		}

		$viewResult = $requestContext->get(ViewResult::class);

		return $viewResult instanceof ViewResult ? $viewResult : null;
	}
}
