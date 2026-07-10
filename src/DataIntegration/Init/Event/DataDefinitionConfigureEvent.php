<?php

declare(strict_types=1);

namespace ON\DataIntegration\Init\Event;

use ON\Data\Definition\Registry;

final class DataDefinitionConfigureEvent
{
	/** @var array<string, mixed>|null */
	private ?array $definitions = null;

	public function __construct(
		public readonly Registry $registry,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function definitions(): array
	{
		$this->definitions ??= $this->registry->all();

		return $this->definitions;
	}
}
