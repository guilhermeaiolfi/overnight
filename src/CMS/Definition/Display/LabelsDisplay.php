<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

class LabelsDisplay extends DisplayDefinition
{
	protected bool $format_each_label = true;

	public function formatEachLabel(bool $format_each_label): self
	{
		$this->format_each_label = $format_each_label;

		return $this;
	}

	public function isFormatEachLabel(): ?bool
	{
		return $this->format_each_label;
	}
}
