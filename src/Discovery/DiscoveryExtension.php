<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;

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
		$this->when('setup', [$this, 'setup']);
		$this->app->ext('container')->when('ready', [$this, 'onContainerReady']);
	}

	public function onContainerReady(): void
	{
		$this->cache = $this->app->container->get(DiscoveryCache::class);
		$this->dispatchStateChange('setup');
	}

	public function get(string $className): ?DiscoverInterface
	{
		return $this->discovers[$className] ?? null;
	}

	public function has(string $className): bool
	{
		return isset($this->discovers[$className]);
	}

	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);

		$app->registerExtension('discovery', $extension);

		$app->discovery = $extension;

		return $extension;
	}

	public function setup(): void
	{
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
		$this->dispatchStateChange('ready');
	}

	public function clear(null|string|DiscoveryLocation $location = null): void
	{
		$resolved = $this->resolveLocation($location);

		foreach ($this->discovers as $discover) {
			if ($resolved === null) {
				$locations = $this->appCfg->get('discovery.locations', []);
				foreach ($locations as $loc) {
					$this->cache->clear($discover, $loc);
				}
			} else {
				$this->cache->clear($discover, $resolved);
			}
		}
	}

	protected function resolveLocation(null|string|DiscoveryLocation $location): ?DiscoveryLocation
	{
		if ($location === null || $location instanceof DiscoveryLocation) {
			return $location;
		}

		$locations = $this->appCfg->get('discovery.locations', []);
		foreach ($locations as $loc) {
			if ($loc->name === $location) {
				return $loc;
			}
		}

		return null;
	}
}
