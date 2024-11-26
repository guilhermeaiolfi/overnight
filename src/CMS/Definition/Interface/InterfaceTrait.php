<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

trait InterfaceTrait
{
	protected ?InterfaceDefinition $interface = null;

	public function interface(string $interface = null): InterfaceDefinition
	{
		$this->interface = new InterfaceDefinition($this);
		if (isset($interface)) {
			$this->interface->type($interface);
		}

		return $this->interface;
	}

	public function getInterface(): InterfaceDefinition
	{
		return $this->interface;
	}
};
