<?php

declare(strict_types=1);

namespace ON\View;

class View implements ViewInterface
{
	protected ?string $defaultTemplateName = null;

	public function __construct(
		protected ViewManager $manager
	) {
	}

	public function render(array $data, ?string $templateName = null, ?string $layoutName = null): string
	{
		return $this->manager->render($this, $data, $templateName, $layoutName);
	}

	public function setDefaultTemplateName(string $templateName): void
	{
		$this->defaultTemplateName = $templateName;
	}

	public function getDefaultTemplateName(): ?string
	{
		return $this->defaultTemplateName;
	}
}
