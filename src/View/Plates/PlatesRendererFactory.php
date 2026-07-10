<?php

declare(strict_types=1);

namespace ON\View\Plates;

use Psr\Container\ContainerInterface;

class PlatesRendererFactory
{
	public function __invoke(ContainerInterface $c)
	{
		// Attention! engine folders are added in the EngineFactory

		$renderer = $c->get(PlatesRenderer::class);

		return $renderer;

	}
}
