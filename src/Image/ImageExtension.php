<?php
 
namespace ON\Image;

use ON\Application;
use ON\Config\RouterConfig;
use ON\Extension\AbstractExtension;
use ON\Router\Route;

class ImageExtension extends AbstractExtension
{
    public static function install(Application $app, ?array $options = []): mixed {
        if (php_sapi_name() == 'cli') {
            return false;
        }
        $extension = new self($app, $options);
        return $extension;
    }

    public function __construct (
        protected Application $app, 
        protected array $options
    )
    {
    }

    public function setup($counter): bool {
        $image_cfg = $this->app->config->get(ImageConfig::class);
        $router_cfg = $this->app->config->get(RouterConfig::class);
        $router_cfg->addRoute(new Route(
            "/teste",
            "App\\Page\\FooPage::index",
            ['GET'],
            "foo.bar"
        ));

        $routes = $router_cfg->get('routes');
        $routes[] = [
            '/' . $image_cfg->get('basePath', "i/") . '{uri:\S+}',
            "ON\Image\ImageManager::process",
            ['GET'],
            "imagemanager"
        ];

        $router_cfg->set('routes', $routes);
        return true;
    }
}