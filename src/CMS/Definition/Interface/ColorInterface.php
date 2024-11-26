<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

// Allow selection of a calor
class ColorInterface extends InterfaceDefinition
{
	protected array $presets = [];

	protected bool $opacity = false;

	public function presets(array $presets): self
	{
		$this->presets = $presets;

		return $this;
	}

	public function getPresets(): array
	{
		return $this->presets;
	}

	public function opacity(bool $opacity): self
	{
		$this->opacity = $opacity;

		return $this;
	}

	public function getOpacity(): bool
	{
		return $this->opacity;
	}
}
