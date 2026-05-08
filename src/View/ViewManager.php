<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use ON\Application;
use ON\Container\Executor\ExecutorInterface;
use ON\Http\RequestContext;
use ON\RequestStackInterface;
use ON\Router\Route;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

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

		$layoutConfig = $this->config->getLayoutConfig($layoutName);
		$rendererName = $layoutConfig['renderer'];
		$renderer = $this->getRendererInstance($rendererName);

		$renderedSections = $this->renderSections($data, $templateName, $rendererName, $layoutConfig, $request);

		$data = $this->injectHelpers($rendererName, $data, $request, $layoutConfig, $templateName);

		return $renderer->render($layoutConfig, $templateName, $data, [
			'request' => $request,
			'mode' => 'layout',
			'sections' => $renderedSections,
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

			return $executor->execute(
				[
					$result->getTargetObject(),
					$result->getViewMethod(),
				],
				$this->resolveViewParameters(
					$result,
					$request,
					$delegate
				)
			);
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
			ServerRequestInterface::class => $request,
			RequestHandlerInterface::class => $delegate,
			'request' => $request,
			'delegate' => $delegate,
			'handler' => $delegate,
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

	protected function getType(mixed $sectionDefinition): array
	{
		if ($sectionDefinition instanceof Route) {
			return [
				'type' => 'uri',
				"uri" => $sectionDefinition->getPath(),
			];
		}

		if (is_array($sectionDefinition) && array_is_list($sectionDefinition)) {
			return [
				'type' => 'uri',
				'uri' => $sectionDefinition[0] ?? '',
			];
		}

		if (is_array($sectionDefinition)) {
			if (array_key_exists('content', $sectionDefinition)) {
				return [
					'type' => 'content',
					'content' => (string) $sectionDefinition['content'],
				];
			}


			if (isset($sectionDefinition['template'])) {
				return [
					'type' => 'template',
					'template' => (string) $sectionDefinition['template'],
					'renderer' => isset($sectionDefinition['renderer']) && is_string($sectionDefinition['renderer'])
						? $sectionDefinition['renderer']
						: null,
				];
			}
		}

		if (is_string($sectionDefinition)) {
			if (str_ends_with($sectionDefinition, '.php') && file_exists($sectionDefinition)) {
				return [
					'type' => 'phpFile',
					'phpFile' => $sectionDefinition,
				];
			}


			if ($this->config->getRendererNameFromTemplateExtension($sectionDefinition) !== null) {
				return [
					'type' => 'template',
					'template' => $sectionDefinition,
					'renderer' => null,
				];
			}

			return [
				'type' => 'content',
				'content' => $sectionDefinition,
			];
		}

		throw new RuntimeException('Invalid section configuration.');

	}

	protected function renderSections(
		array &$data,
		string $templateName,
		string $outerRenderer,
		array $layoutConfig,
		?ServerRequestInterface $request
	): array {
		$sections = [];

		foreach ($layoutConfig['sections'] ?? [] as $sectionName => $sectionDefinition) {

			$sections[$sectionName] = [
				'type' => 'text',
				'content' => $this->renderSection(
					$data,
					$this->getType($sectionDefinition),
					$outerRenderer,
					$layoutConfig,
					$request
				),
			];
		}

		$sections['content'] = [
			'type' => 'text',
			'content' => $this->renderSection(
				$data,
				$this->getType(['template' => $templateName]),
				$outerRenderer,
				$layoutConfig,
				$request
			),
		];

		return $sections;
	}

	protected function renderSection(
		array &$data,
		array $sectionDefinition,
		string $outerRenderer,
		array $layoutConfig,
		?ServerRequestInterface $request
	): string {
		switch ($sectionDefinition['type'] ?? null) {
			case 'content':
				return (string) $sectionDefinition['content'];

			case 'uri':
				$app = $this->container->get(Application::class);

				$response = $app->processForward(
					$sectionDefinition['uri'],
					$request,
					'GET'
				);

				return (string) $response->getBody();

			case 'phpFile':
				ob_start();
				include $sectionDefinition['phpFile'];

				return (string) ob_get_clean();

			case 'template':
				$templateName = (string) $sectionDefinition['template'];
				$explicitRenderer = $sectionDefinition['renderer'] ?? null;
				[$normalizedTemplate, $inferredRenderer] = $this->config->normalizeTemplateReference($templateName);
				$rendererName = $explicitRenderer ?? $inferredRenderer ?? $outerRenderer;
				$templateReference = ($explicitRenderer !== null && $explicitRenderer !== $inferredRenderer)
					? $templateName
					: $normalizedTemplate;

				$data = $this->injectHelpers($rendererName, $data, $request, $layoutConfig, $templateReference);

				$renderer = $this->getRendererInstance($rendererName);

				return $renderer->render($layoutConfig, $templateReference, $data, [
					'request' => $request,
					'mode' => 'fragment',
				]);
		}

		throw new Exception('Invalid section configuration.');
	}

	protected function getRendererInstance(string $rendererName): RendererInterface
	{
		$rendererClass = $this->config->getRendererClass($rendererName);
		$renderer = $this->container->get($rendererClass);

		if (! $renderer instanceof RendererInterface) {
			throw new Exception(sprintf('Renderer "%s" is invalid.', $rendererName));
		}

		return $renderer;
	}

	protected function injectHelpers(
		string $rendererName,
		array $data,
		?ServerRequestInterface $request,
		array $layoutConfig,
		string $templateName
	): array {
		$rendererConfig = $this->config->getRendererConfig($rendererName);
		if (empty($rendererConfig['inject'])) {
			return $data;
		}

		$renderContext = new RenderContext(
			$this->container,
			$request,
			$data,
			['layout' => $layoutConfig, 'template' => $templateName]
		);

		foreach ($rendererConfig['inject'] as $key => $class) {
			if (array_key_exists($key, $data)) {
				continue;
			}

			$data[$key] = $this->resolveInjectedValue($class, $renderContext);
		}

		return $data;
	}
}
