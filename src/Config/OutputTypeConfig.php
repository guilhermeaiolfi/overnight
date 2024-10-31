<?php

declare(strict_types=1);

namespace ON\Config;

class OutputTypeConfig
{
	protected ?OutputTypeConfig $default = null;

	protected array $types = [];

	public function __construct(
		public ?array $renderers = [],
		public ?array $layouts = []
	) {

	}
}
