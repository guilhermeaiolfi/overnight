<?php

declare(strict_types=1);

namespace ON\Init\Attribute;

use Attribute;
use ON\Extension\ExtensionInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class InitEvent
{
	/**
	 * @param class-string<ExtensionInterface>|null $owner
	 */
	public function __construct(
		public ?string $owner = null,
		public bool $lazy = false,
	) {
	}
}
