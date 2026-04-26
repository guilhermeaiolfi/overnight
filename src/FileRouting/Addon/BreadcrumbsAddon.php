<?php

declare(strict_types=1);

namespace ON\FileRouting\Addon;

class BreadcrumbsAddon implements FileRoutingAddonInterface
{
	public function process(array $pageContext, array $data): array
	{
		if (isset($data['_breadcrumbs'])) {
			return $data;
		}

		$pageMeta = $pageContext['metadata'] ?? [];

		if (($pageMeta['breadcrumbs'] ?? null) === false) {
			$data['_breadcrumbs'] = [];

			return $data;
		}

		if (isset($pageMeta['breadcrumbs']) && is_array($pageMeta['breadcrumbs'])) {
			$data['_breadcrumbs'] = $this->interpolateBreadcrumbs($pageMeta['breadcrumbs'], $pageContext, $data);

			return $data;
		}

		$data['_breadcrumbs'] = $this->buildBreadcrumbs($pageContext, $pageMeta, $data);

		return $data;
	}

	protected function buildBreadcrumbs(array $pageContext, array $pageMeta, array $data): array
	{
		$request = $pageContext['request'] ?? null;
		$requestPath = $request?->getUri()->getPath() ?? ($pageContext['requestPath'] ?? '');
		$requestPath = trim((string) $requestPath, '/');
		$urlSegments = $requestPath === '' ? [] : explode('/', $requestPath);
		$routeSegments = $this->getRouteSegments($pageContext);
		$breadcrumbs = [
			[
				'label' => $pageMeta['breadcrumbHomeLabel'] ?? 'Inicio',
				'url' => $pageMeta['breadcrumbHomeUrl'] ?? '/',
			],
		];

		$total = count($routeSegments);
		foreach ($routeSegments as $index => $segment) {
			$isLast = $index === $total - 1;
			$actualSegment = $urlSegments[$index] ?? $segment;
			$item = [
				'label' => $this->getBreadcrumbLabel($segment, $actualSegment, $isLast, $pageContext, $pageMeta, $data),
			];

			if (! $isLast) {
				$item['url'] = '/' . implode('/', array_slice($urlSegments, 0, $index + 1));
			}

			$breadcrumbs[] = $item;
		}

		return $breadcrumbs;
	}

	protected function interpolateBreadcrumbs(array $breadcrumbs, array $pageContext, array $data): array
	{
		return array_map(function (array $item) use ($pageContext, $data): array {
			foreach (['label', 'url'] as $key) {
				if (isset($item[$key]) && is_string($item[$key])) {
					$item[$key] = $this->interpolate($item[$key], $pageContext, $data);
				}
			}

			return $item;
		}, $breadcrumbs);
	}

	protected function getRouteSegments(array $pageContext): array
	{
		$relative = str_replace('\\', '/', (string) ($pageContext['relativeFile'] ?? ''));
		$relative = preg_replace('/\.php$/', '', $relative);
		$segments = array_values(array_filter(explode('/', $relative), static fn ($segment) => $segment !== ''));

		if (end($segments) === 'index') {
			array_pop($segments);
		}

		return $segments;
	}

	protected function getBreadcrumbLabel(
		string $segment,
		string $actualSegment,
		bool $isLast,
		array $pageContext,
		array $pageMeta,
		array $data
	): string {
		if ($isLast) {
			if (isset($data['_title']) && is_string($data['_title']) && $data['_title'] !== '') {
				return $data['_title'];
			}

			if (isset($pageMeta['title']) && is_string($pageMeta['title']) && $pageMeta['title'] !== '') {
				return $pageMeta['title'];
			}
		}

		if (preg_match('/^\[([^\]]+)\]$/', $segment, $matches)) {
			$param = $matches[1];
			$labels = $pageMeta['breadcrumbLabels'] ?? [];
			if (isset($labels[$param]) && is_string($labels[$param])) {
				return $this->interpolate($labels[$param], $pageContext, $data);
			}

			return (string) ($pageContext['params'][$param] ?? $actualSegment);
		}

		return $this->titleFromSlug($segment);
	}

	protected function titleFromSlug(string $slug): string
	{
		return ucwords(str_replace(['-', '_'], ' ', $slug));
	}

	protected function interpolate(string $template, array $pageContext, array $data): string
	{
		return preg_replace_callback('/{{\s*([a-zA-Z0-9_.-]+)\s*}}/', static function (array $matches) use ($pageContext, $data) {
			$key = $matches[1];
			if (array_key_exists($key, $data)) {
				return (string) $data[$key];
			}
			if (array_key_exists($key, $pageContext['params'] ?? [])) {
				return (string) $pageContext['params'][$key];
			}
			if (array_key_exists($key, $pageContext)) {
				return (string) $pageContext[$key];
			}

			return $matches[0];
		}, $template);
	}
}
