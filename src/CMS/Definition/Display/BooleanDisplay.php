<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

class BooleanDisplay extends DisplayDefinition
{
	protected ?string $label_on = null;
	protected ?string $label_off = null;

	protected ?string $icon_on = null;
	protected ?string $icon_off = null;

	protected ?string $color_on = null;
	protected ?string $color_off = null;

	public function labelOn(bool $label): self
	{
		$this->label_on = $label;

		return $this;
	}

	public function getLabelOn(): ?string
	{
		return $this->label_on;
	}

	public function labelOff(bool $label): self
	{
		$this->label_off = $label;

		return $this;
	}

	public function getLabelOff(): ?string
	{
		return $this->label_off;
	}

	public function iconOn(bool $icon): self
	{
		$this->icon_on = $icon;

		return $this;
	}

	public function getIconOn(): ?string
	{
		return $this->icon_on;
	}

	public function iconOff(bool $icon): self
	{
		$this->icon_off = $icon;

		return $this;
	}

	public function getIconOff(): ?string
	{
		return $this->icon_off;
	}

	public function colorOn(bool $color): self
	{
		$this->color_on = $color;

		return $this;
	}

	public function getColorOn(): ?string
	{
		return $this->color_on;
	}

	public function colorOff(bool $color): self
	{
		$this->color_off = $color;

		return $this;
	}

	public function getColorOff(): ?string
	{
		return $this->color_off;
	}
}
