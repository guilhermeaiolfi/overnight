<?php

declare(strict_types=1);

namespace ON\Config;

use ON\View\Plates\PlatesRenderer;

class RendererConfig
{
	public function __construct(
		public ?string $class = PlatesRenderer::class,
		public ?array $inject = [],
	) {
	}
}
