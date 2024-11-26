<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

trait DisplayTrait
{
	protected ?DisplayDefinition $display = null;

	public function display(string $type = RawDisplay::class): DisplayDefinition
	{
		$this->display = new $type($this);

		return $this->display;
	}

	public function getDisplay(): DisplayDefinition
	{
		return $this->display;
	}
};
