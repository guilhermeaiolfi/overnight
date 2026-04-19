<?php

declare(strict_types=1);

namespace ON\CMS;

use ON\Application;
use ON\CMS\Page\CollectionPage;
use ON\CMS\Page\ItemsPage;
use ON\Container\ContainerConfig;
use ON\Extension\AbstractExtension;
use ON\Extension\ExtensionInterface;
use ON\Router\RouterExtension;

class CMSExtension extends AbstractExtension
{
	public static function install(Application $app, ?array $options = []): ?ExtensionInterface
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options
	) {
	}

	public function boot(): void
	{
		$this->when('installed', [$this, 'setup']);

		if ($this->app->isCli()) {
			$this->app->ext('console')->when('ready', function ($console) {
			});
		}

		$this->app->ext('config')->when('setup', function ($configExt) {
			$containerConfig = $configExt->get(ContainerConfig::class);
			//			$containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
		});

		$this->app->ext('router')->when('setup', [$this, 'onRouterSetup']);
	}

	public function setup(): void
	{
	}

	public function onRouterSetup(RouterExtension $router): void
	{
		$router->get("/items/{collection}", ItemsPage::class . "::all", "cms.items.all");
		$router->get("/items/{collection}/{id:\d+}", ItemsPage::class . "::getOne", "cms.items.one");

		$router->get("/collection", CollectionPage::class . "::all", "cms.collection.all");
		$router->get("/collection/{id:\d+}", CollectionPage::class . "::getOne", "cms.collection.one");

		$this->dispatchStateChange('ready');
	}

	public function onContainerConfig(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
