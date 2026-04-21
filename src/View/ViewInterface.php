<?php

declare(strict_types=1);

namespace ON\View;

/**
 * Interface implemented by render-context view instances created by ViewManager.
 */
interface ViewInterface
{
	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string;

	public function setDefaultTemplateName(string $templateName): void;

	public function getDefaultTemplateName(): ?string;
}
