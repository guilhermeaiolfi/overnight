<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use ON\Container\Executor\ExecutorInterface;

trait ViewBuilderTrait
{
	public function buildView($page, $action_name, $response, $request, $delegate)
	{
		// ViewResult: explicit view name + data
		if ($response instanceof ViewResult) {
			return $this->resolveViewMethod($page, $action_name, $response, $request, $delegate);
		}

		// String: view method name shorthand (empty data)
		if (is_string($response)) {
			return $this->resolveViewMethod($page, $action_name, new ViewResult($response), $request, $delegate);
		}

		// Response or anything else: return as-is
		return $response;
	}

	protected function resolveViewMethod($page, string $action_name, ViewResult $result, $request, $delegate)
	{
		$view = $page;
		$viewName = $result->view;

		// Cross-page view reference: "OtherPage:methodName"
		if (strpos($viewName, ":") !== false) {
			$parts = explode(":", $viewName);
			$pageClass = $parts[0] . 'Page';
			$viewName = $parts[1];

			if ($pageClass !== get_class($page)) {
				$view = $this->container->get($pageClass);
			}
		} else {
			$viewName = strtolower($viewName);
		}

		// Set default template name convention on the page's ViewInterface (found via reflection)
		$viewInstance = $this->findViewOnPage($view);
		if ($viewInstance !== null) {
			$path = explode("\\", get_class($view));
			$templateName = strtolower($path[0] . "::" . str_replace("Page", "", array_pop($path)) . "-" . $action_name . "-" . strtolower($viewName));
			$viewInstance->setDefaultTemplateName($templateName);
		}

		// Resolve the view method name
		$absoluteViewMethod = $this->findViewMethod($view, $viewName, $action_name);

		// Use Executor if available — lets the view method declare whatever parameters it wants
		// (ViewResult, ServerRequestInterface, individual data keys, etc.)
		if (isset($this->executor) && $this->executor instanceof ExecutorInterface) {
			$parameters = array_merge($result->data, [
				ViewResult::class => $result,
				'result' => $result,
				'request' => $request,
				'delegate' => $delegate,
				'data' => $result->data,
			]);

			return $this->executor->execute([$view, $absoluteViewMethod], $parameters);
		}

		// Fallback: call directly with ViewResult as first arg
		return $view->{$absoluteViewMethod}($result, $request, $delegate);
	}

	/**
	 * Find a ViewInterface instance on the page by scanning its properties via reflection.
	 * Works regardless of what the developer named the property.
	 */
	protected function findViewOnPage(object $page): ?ViewInterface
	{
		$ref = new \ReflectionObject($page);
		foreach ($ref->getProperties() as $prop) {
			$type = $prop->getType();
			if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				if (is_a($type->getName(), ViewInterface::class, true)) {
					$prop->setAccessible(true);
					$value = $prop->getValue($page);
					if ($value instanceof ViewInterface) {
						return $value;
					}
				}
			}
		}
		return null;
	}

	protected function findViewMethod(object $page, string $viewName, string $actionName): string
	{
		// Try: successView
		$method = strtolower($viewName) . 'View';
		if (method_exists($page, $method)) {
			return $method;
		}

		// Try: createSuccessView
		$method = $actionName . ucfirst($viewName) . 'View';
		if (method_exists($page, $method)) {
			return $method;
		}

		// Try: success (without View suffix — new convention)
		$method = strtolower($viewName);
		if (method_exists($page, $method)) {
			return $method;
		}

		$className = get_class($page);
		throw new Exception("No view method found for '{$viewName}' in class {$className}");
	}
}
