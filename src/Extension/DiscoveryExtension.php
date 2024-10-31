<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;
use ON\Config\AppConfig;
use ON\Discovery\ClassFinder;
use ON\Event\EventSubscriberInterface;
use Symfony\Component\Finder\Finder;

class DiscoveryExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const NAMESPACE = "core.extensions.discovery";
	protected int $type = self::TYPE_EXTENSION;

	protected array $discovers = [];
	protected array $pendingProcess = [];

	protected array $pendingTasks = [ 'discovery:create' ];

	public ClassFinder $classFinder;
	protected array $files;
	protected AppConfig $appCfg;

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		$this->classFinder = new ClassFinder();
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function setup(int $counter): bool
	{
		if (! $this->hasPendingTasks()) {
			return true;
		}

		return false;
	}

	public function ready()
	{

	}

	public function onConfigReady(): void
	{
		$this->appCfg = $this->app->config->get(AppConfig::class);

		$discovers = array_keys($this->appCfg->get('discovery.discoverers', []));

		foreach ($discovers as $className) {
			$this->discovers[] = new $className($this->app);
		}
		$pattern = $this->appCfg->get('discovery.pattern');

		$discovers = $this->discovers;

		$oldest = 0;
		foreach ($discovers as $discover) {
			$timestamp = $discover->cachedTimestamp();
			if ($oldest == 0 || $oldest > $timestamp) {
				$oldest = $timestamp;
			}
			if ($timestamp > 0) {
				$discover->recover();
			}
		}
		$finder = new Finder();

		$finder->files()->in($pattern)->date(">= " . date("d.m.Y H:i:s", (int) $oldest));
		foreach ($finder as $file) {
			$timestamp = $discover->cachedTimestamp();
			if ($timestamp == 0 || $this->app->isDebug()) {
				foreach ($discovers as $discover) {
					if ($file->getMTime() > $timestamp) {
						$discover->updateFile($file);
					}
				}
			}
		}

		foreach ($discovers as $discover) {
			$discover->save();
			$discover->process();
		}

		$this->removePendingTask('discovery:create');
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.extensions.config.ready' => 'onConfigReady',
		];
	}
}
