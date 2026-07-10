<?php

declare(strict_types=1);

namespace ON\View;

use ON\Config\Config;
use ON\Router\UrlHelper;
use ON\View\Config\FormatNode;

class ViewConfig extends Config
{
	public array $helpers = [
		'url' => UrlHelper::class,
	];

	public function format(string $name): FormatNode
	{
		if (! isset($this->items['formats'][$name])) {
			$this->items['formats'][$name] = [];
		}

		return new FormatNode($this->items['formats'][$name], $this);
	}

	/**
	 * No longer strictly needed for serialization, but kept for API compatibility.
	 */
	public function done(): array
	{
		return $this->all();
	}

	public function getLayoutConfig(string $layoutName): ?array
	{
		$layoutConfig = $this->get("formats.html.layouts.{$layoutName}");
		if (! is_array($layoutConfig)) {
			return null;
		}

		$layoutConfig['name'] = $layoutName;

		return $layoutConfig;
	}

	public function getRendererConfig(string $rendererName): ?array
	{
		$rendererConfig = $this->get("formats.html.renderers.{$rendererName}");

		return $rendererConfig;
	}

	public function getRendererClass(string $rendererName): ?string
	{
		$rendererConfig = $this->getRendererConfig($rendererName);

		if ($rendererConfig === null) {
			return null;
		}

		return $rendererConfig['class'] ?? '\ON\Renderer';
	}

	public function getRendererExtension(string $rendererName): ?string
	{
		$rendererConfig = $this->getRendererConfig($rendererName);
		if (! empty($rendererConfig['extension']) && is_string($rendererConfig['extension'])) {
			return $rendererConfig['extension'];
		}

		return match ($rendererName) {
			'plates' => $this->get('templates.extension', 'phtml'),
			'latte' => $this->get('latte.extension', 'latte'),
			default => null,
		};
	}

	public function getRendererNameFromTemplateExtension(string $templateName): ?string
	{
		$extension = $this->extractTemplateExtension($templateName);
		if ($extension === null) {
			return null;
		}

		foreach (array_keys($this->get('formats.html.renderers', [])) as $rendererName) {
			if ($this->getRendererExtension($rendererName) === $extension) {
				return $rendererName;
			}
		}

		return null;
	}

	public function extractTemplateExtension(string $templateName): ?string
	{
		$templatePath = str_contains($templateName, '::')
			? explode('::', $templateName, 2)[1]
			: $templateName;

		$extension = pathinfo($templatePath, PATHINFO_EXTENSION);

		return $extension !== '' ? $extension : null;
	}
}
