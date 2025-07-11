<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use Symfony\Component\Finder\Finder;

class DiscoveryExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const NAMESPACE = "core.extensions.discovery";
	protected int $type = self::TYPE_EXTENSION;

	protected array $discovers = [];
	protected array $pendingProcess = [];

	protected array $pendingTasks = [ ];

	public ClassFinder $classFinder;
	protected array $files;
	protected AppConfig $appCfg;

	protected DiscoveryCache $cache;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$this->classFinder = new ClassFinder();
		$this->cache = new DiscoveryCache();
	}

	public function get(string $className): DiscoverInterface
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

	protected function restore($discovers): float
	{
		$oldest = 0;
		foreach ($discovers as $discover) {
			$timestamp = $this->cache->timestamp($discover);
			if ($oldest == 0 || $oldest > $timestamp) {
				$oldest = $timestamp;
			}
			if ($timestamp > 0) {
				$this->cache->read($discover);
			}
		}

		return $oldest;
	}

	public function setup(): void
	{
		if (! $this->app->ext('config')->isReady()) {
			$this->nextTick([$this, 'setup']);

			return;
		}

		$this->appCfg = $this->app->config->get(AppConfig::class);

		$discovers = array_keys($this->appCfg->get('discovery.discoverers', []));

		$pattern = $this->appCfg->get('discovery.pattern');

		$this->setupDiscovers($discovers, $pattern);

		$this->setState('ready');
	}

	public function setupDiscovers(array $discovers, $pattern): void
	{
		foreach ($discovers as $className) {
			$this->discovers[$className] = new $className($this->app);
		}

		$oldest = $this->restore($this->discovers);

		$finder = new Finder();

		$finder->files()->in($pattern)->date(">= " . date("d.m.Y H:i:s", (int) $oldest));
		foreach ($finder as $file) {
			if ($oldest == 0 || $this->app->isDebug()) {
				foreach ($this->discovers as $discover) {
					$timestamp = $this->cache->timestamp($discover);
					if ($file->getMTime() > $timestamp) {
						$discover->discover($file);
					}
				}
			}
		}

		foreach ($this->discovers as $discover) {
			$this->cache->save($discover);
			$discover->apply();
		}
	}

	public function clear(): void
	{
		foreach ($this->discovers as $discover) {
			$this->cache->clear($discover);
		}
	}

	public function isVendor($path): bool
	{
		return str_contains($path, '/vendor/')
			|| str_contains($path, '\\vendor\\');
	}

	public static function getSubscribedEvents()
	{
		return [
			//'core.extensions.config.ready' => 'onConfigReady',
		];
	}
}
