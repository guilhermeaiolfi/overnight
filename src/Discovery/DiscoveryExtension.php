<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Extension\AbstractExtension;

class DiscoveryExtension extends AbstractExtension
{
	public const NAMESPACE = "core.extensions.discovery";
	protected int $type = self::TYPE_EXTENSION;

	protected array $discovers = [];
	protected array $pendingProcess = [];

	protected array $pendingTasks = [ ];

	protected array $files;
	protected AppConfig $appCfg;

	protected DiscoveryCache $cache;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		//$this->cache = $app->container->get(DiscoveryCache::class);
	}

	public function boot(): void
	{
		$this->app->ext('container')->when('ready', [$this, 'onContainerReady']);
	}

	public function onContainerReady(): void
	{
		$this->cache = $this->app->container->get(DiscoveryCache::class);
	}

	public function get(string $className): ?DiscoverInterface
	{
		return $this->discovers[$className] ?? null;
	}

	public function has(string $className): bool
	{
		return isset($this->discovers[$className]);
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		$app->registerExtension('discovery', $extension);

		$app->discovery = $extension;

		return $extension;
	}

	public function setup(): void
	{
		if (! $this->app->ext('config')->isReady() || ! isset($this->cache)) {
			$this->nextTick([$this, 'setup']);

			return;
		}

		$this->appCfg = $this->app->config->get(AppConfig::class);

		$discoverClassnames = array_keys($this->appCfg->get('discovery.discoverers', []));

		$locations = $this->appCfg->get('discovery.locations', []);


		// creates the discovers instances
		foreach ($discoverClassnames as $className) {
			$this->discovers[$className] = $this->app->container->get($className);
		}

		foreach ($locations as $location) {
			// set the discovers up to the cache state
			foreach ($this->discovers as $discover) {
				$this->cache->recover($discover, $location);
			}

			// now we go after the changes made after the cache was created
			$this->cache->update($this->discovers, $location);

			foreach ($this->discovers as $discover) {
				$this->cache->save($discover, $location);
				$discover->apply();
			}
		}


		// we are now fully ready
		$this->setState('ready');
	}

	public function clear(?string $location = null): void
	{
		foreach ($this->discovers as $discover) {
			$this->cache->clear($discover, $location);
		}
	}
}
