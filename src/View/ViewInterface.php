<?php

declare(strict_types=1);

namespace ON\View;

/**
 * Interface for view rendering.
 *
 * Pages that need template rendering declare this in their constructor.
 * Data is passed explicitly to render() — no shared mutable state.
 */
interface ViewInterface
{
	/**
	 * Render a template with the given data.
	 *
	 * @param array $data Template data (required)
	 * @param string|null $templateName Template name (null = use defaultTemplateName)
	 * @param string|null $layoutName Layout name (null = default from config)
	 * @return string Rendered HTML
	 */
	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string;

	public function setDefaultTemplateName(string $templateName): void;

	public function getDefaultTemplateName(): ?string;
}
