<?php

declare(strict_types=1);

namespace ON\Config\Scanner;

class AttributeHolder
{
	public function __construct(
		public string $className,
		public ?string $targetName = null,
		public int $targetType = 0,
		public mixed $instance = null
	) {

	}
}
