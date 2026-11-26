<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Latte\Engine;
use ON\Application;
use ON\View\ViewConfig;
use Psr\Container\ContainerInterface;

class LatteRendererFactory
{
	public function __invoke(ContainerInterface $c, ViewConfig $config): LatteRenderer
	{
		$latte = $c->get(Engine::class);
		$app = $c->get(Application::class);

		$latte->addExtension(new SectionLatteExtension());

		if (isset($config['latte']['tempDirectory']) && is_string($config['latte']['tempDirectory']) && $config['latte']['tempDirectory'] !== '') {
			$latte->setTempDirectory($config['latte']['tempDirectory']);
		}

		$autoRefresh = filter_var($config['latte']['autorefresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$latte->setAutoRefresh($autoRefresh);

		$renderer = new LatteRenderer($config, $latte, $app, $c);

		return $renderer;

	}
}
