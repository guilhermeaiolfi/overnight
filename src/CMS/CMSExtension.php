<?php

declare(strict_types=1);

namespace ON\CMS;

use ON\Application;
use ON\CMS\Page\CollectionPage;
use ON\CMS\Page\ItemsPage;
use ON\Container\ContainerConfig;
use ON\Config\Init\ConfigInitEvents;
use ON\Console\Init\ConsoleInitEvents;
use ON\Extension\AbstractExtension;
use ON\Init\Init;
use ON\Router\RouterExtension;
use ON\Router\Init\RouterInitEvents;

class CMSExtension extends AbstractExtension
{
	public const ID = 'cms';
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
	}

	public function register(Init $init): void
	{
		if ($this->app->isCli()) {
			$init->on(ConsoleInitEvents::READY, function (): void {
			});
		}

		$init->on(ConfigInitEvents::SETUP, function (object $event): void {
			$containerConfig = $event->config->get(ContainerConfig::class);
			//			$containerConfig->addFactory(CycleDatabase::class, CycleDatabaseFactory::class);
		});

		$init->on(RouterInitEvents::SETUP, [$this, 'onRouterSetup']);
	}

	public function start(\ON\Init\InitContext $context): void
	{
	}

	public function onRouterSetup(RouterExtension $router): void
	{
		$router->get("/items/{collection}", ItemsPage::class . "::all", "cms.items.all");
		$router->get("/items/{collection}/{id:\d+}", ItemsPage::class . "::getOne", "cms.items.one");

		$router->get("/collection", CollectionPage::class . "::all", "cms.collection.all");
		$router->get("/collection/{id:\d+}", CollectionPage::class . "::getOne", "cms.collection.one");

	}

	public function onContainerConfigure(): void
	{

	}

	public function onConfigSetup(): void
	{

	}
}
