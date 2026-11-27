<?php

declare(strict_types=1);

namespace ON\Cache\Container;

use Exception;
use ON\Cache\CacheConfig;
use ON\Container\ApplicationBinding;
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

		$app = ApplicationBinding::get($c);
		$defaultDirectory = $app?->paths->get('cache')->append('symfony')->getAbsolutePath() ?? 'var/cache/symfony';

		$directory = $config->get("adapter.directory", $defaultDirectory);
		if ($app !== null) {
			$directory = Path::from($directory, $app->paths->get('project'))
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
