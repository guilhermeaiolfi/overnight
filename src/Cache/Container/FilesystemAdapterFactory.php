<?php

declare(strict_types=1);

namespace ON\Cache\Container;

use Exception;
use ON\Application;
use ON\Cache\CacheConfig;
use ON\FS\Path;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class FilesystemAdapterFactory
{
	public function __invoke(ContainerInterface $c)
	{
		if (! $c->has(CacheConfig::class)) {
			throw new Exception("There is no CacheConfig object registered in the container. Please provide one.");
		}
		$config = $c->get(CacheConfig::class);

		$defaultDirectory = 'var/cache/symfony';
		if ($c->has(Application::class)) {
			$defaultDirectory = $c->get(Application::class)->paths->get('cache')->append('symfony')->getAbsolutePath();
		}

		$directory = $config->get("adapter.directory", $defaultDirectory);
		if ($c->has(Application::class)) {
			$directory = Path::from($directory, $c->get(Application::class)->paths->get('project'))
				->getAbsolutePath();
		}
		$namespace = $config->get("adapter.namespace", "ON");
		$defaultLifetime = $config->get("adapter.defaultLifetime", 0);

		$adapter = new FilesystemAdapter($namespace, $defaultLifetime, $directory);

		$logger_class = $config->get("adapter.logger", null);
		if ($logger_class != null) {
			$logger = $c->get(LoggerInterface::class);
			$adapter->setLogger($logger);
		}

		return $adapter;
	}
}
