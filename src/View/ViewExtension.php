<?php

declare(strict_types=1);

namespace ON\View;

use Exception;
use League\Plates\Engine;
use ON\Application;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\View\Plates\PlatesEngineFactory;

class ViewExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;

	protected array $pendingTasks = [ "container:define" ];

	public function __construct(
		protected Application $app
	) {
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		if (php_sapi_name() == 'cli') {
			return false;
		}
		$class = self::class;
		$extension = new $class($app, $options);
		$app->registerExtension('view', $extension); // register shortcut
		$app->view = $extension;

		return $extension;
	}

	public function setup(int $counter): bool
	{
		if ($this->removePendingTask("container:define")) {
			$config = $this->app->config;

			if (! isset($config)) {
				throw new Exception("Router Extension needs the config extension");
			}
			$containerConfig = $config->get(ContainerConfig::class);

			// TODO: plates and lattes should be an extension by its own
			$containerConfig->mergeRecursiveDistinct([
				"definitions" => [
					"factories" => [
						Engine::class => PlatesEngineFactory::class,
					],
					"aliases" => [
					],
				],
			]);

			return true;
		}

		return false;
	}

	public function ready()
	{

	}
}
