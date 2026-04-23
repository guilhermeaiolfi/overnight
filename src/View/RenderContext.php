<?php

declare(strict_types=1);

namespace ON\View;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

class RenderContext
{
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly ?ServerRequestInterface $request = null,
		public readonly ?array $data = [],
		public readonly array $params = []
	) {
	}
}
