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

		if (isset($config["latte"]["tempDirectory"])) {
			$latte->setTempDirectory($config["latte"]["tempDirectory"] ?? []);
		}

		$latte->setAutoRefresh($config["latte"]["autorefresh"]);

		$renderer = new LatteRenderer($config, $latte, $app, $c);

		return $renderer;

	}
}
