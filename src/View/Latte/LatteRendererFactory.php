<?php

declare(strict_types=1);

namespace ON\View\Latte;

use Latte\Engine;
use ON\Application;
use ON\FS\Path;
use ON\View\ViewConfig;
use Psr\Container\ContainerInterface;

class LatteRendererFactory
{
	public function __invoke(ContainerInterface $c, ViewConfig $config): LatteRenderer
	{
		$latte = $c->get(Engine::class);
		$app = $c->get(Application::class);

		$latte->addExtension(new SectionLatteExtension());

		$tempDirectory = $config['latte']['tempDirectory'] ?? null;
		if (is_string($tempDirectory) && $tempDirectory !== '') {
			$latte->setTempDirectory(Path::from($tempDirectory, $app->paths->get('project'))->getAbsolutePath());
		}

		$autoRefresh = filter_var($config['latte']['autorefresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$latte->setAutoRefresh($autoRefresh);

		$renderer = new LatteRenderer($config, $latte, $app, $c);

		return $renderer;

	}
}
