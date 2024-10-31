<?php

declare(strict_types=1);

namespace ON\Cache;

use Exception;
use ON\Application;
use ON\Cache\Container\CacheFactory;
use ON\Cache\Container\FilesystemAdapterFactory;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CacheExtension extends AbstractExtension
{
	protected array $pendingTasks = [ "container:define" ];

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function setup($counter): bool
	{
		if ($this->removePendingTask("container:define")) {
			$config = $this->app->config;

			if (! isset($config)) {
				throw new Exception("Cache Extension needs the config extension");
			}
			$containerConfig = $config->get(ContainerConfig::class);
			$containerConfig->mergeConfigArray([
				"definitions" => [
					"aliases" => [
						//CacheInterface::class                      => \Symfony\Contracts\Cache\CacheInterface::class
					],
					"factories" => [
						CacheInterface::class => CacheFactory::class,
						FilesystemAdapter::class => FilesystemAdapterFactory::class,
					],
				],
			]);
		}

		if (empty($this->getPendingTasks())) {
			return true;
		}

		return false;
	}
}
