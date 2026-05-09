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

		if (! is_array($layoutConfig)) {
			throw new RuntimeException(sprintf('There is no configuration for layout name: "%s"', $layoutName));
		}

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
		} else if (is_string($response)) {
			$response = ViewResult::for($response);
		}

		if (! $response instanceof ViewResult) {
			throw new Exception('Response invalid', 1);
		}

		$response->setActionName($actionName);

		if ($response->getPageClass() === null) {
			$response->setTargetObject($actionPage);
		} else {
			$targetObject = $this->container->get($response->getPageClass());
			$response->setTargetObject($targetObject);
		}

		return $this->requestStack->usingRequest($request, function (ServerRequestInterface $request) use (
			$response,
			$delegate,
			$executor
		): mixed {
			$requestContext = $request->getAttribute(RequestContext::class);
			if ($requestContext instanceof RequestContext) {
				$requestContext->set(ViewResult::class, $response);
			}

			return $executor->execute(
				[
					$response->getTargetObject(),
					$response->getViewMethod(),
				],
				$this->resolveViewParameters(
					$response,
					$request,
					$delegate
				)
			);
		});
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
			$helper = $this->createInjectedValue($class, $renderContext);
			$parameters[$class] ??= $helper;
		}

		return $parameters;
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
		string $outerRendererName,
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
				$explicitRendererName = $sectionDefinition['renderer'] ?? null;

				$inferredRendererName = $this->config->getRendererNameFromTemplateExtension($templateName);
				$templateNameWithoutExtension = null;
				if ($inferredRendererName === null) { // that was no extension or there is no renderer for that extension
					$templateNameWithoutExtension = $templateName;
				}

				$extension = $this->config->extractTemplateExtension($templateName);
				if ($extension === null) {
					$templateNameWithoutExtension = $templateName;
				}

				if ($templateNameWithoutExtension === null) {
					$templateNameWithoutExtension = substr($templateName, 0, -strlen('.' . $extension));
				}
				$rendererName = $explicitRendererName ?? $inferredRendererName ?? $outerRendererName;
				$templateNameWithoutExtension = ($explicitRendererName !== null && $explicitRendererName !== $inferredRendererName)
					? $templateName
					: $templateNameWithoutExtension;

				$data = $this->injectHelpers($rendererName, $data, $request, $layoutConfig, $templateNameWithoutExtension);

				$renderer = $this->getRendererInstance($rendererName);

				return $renderer->render($layoutConfig, $templateNameWithoutExtension, $data, [
					'request' => $request,
					'mode' => 'fragment',
				]);
		}

		throw new Exception('Invalid section configuration.');
	}

	protected function getRendererInstance(string $rendererName): RendererInterface
	{
		$rendererClass = $this->config->getRendererClass($rendererName);

		if ($rendererClass === null) {
			throw new RuntimeException(sprintf('There is no configuration for renderer name: "%s"', $rendererName));
		}

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

		if (! is_array($rendererConfig)) {
			throw new RuntimeException(sprintf('There is no configuration for renderer name: "%s"', $rendererName));
		}

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

			$data[$key] = $this->createInjectedValue($class, $renderContext);
		}

		return $data;
	}

	protected function createInjectedValue(string $class, RenderContext $context): mixed
	{
		if (is_a($class, RenderContextAwareHelperInterface::class, true)) {
			return $class::createFromRenderContext($context);
		}

		return $this->container->get($class);
	}

}
