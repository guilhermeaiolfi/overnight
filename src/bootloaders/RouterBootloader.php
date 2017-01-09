<?php 
namespace ON\bootloaders;

class RouterBootloader {
	public function __invoke (\ON\Application $app) { 
	    $app->container->prepare('\ON\Router', function($obj) use ($app) {
	      $obj->setApplication($app);
	    });

	    $app->container->define('\ON\Router', [':basepath' => $app->config->get('paths.base_uri')]);

	    $app->router = $app->container->get('\ON\Router');
	    //$app->router->setApplication($app);
	    if ($routes = $app->config->get('routes')) {
	      $app->router->addRoutes($routes);
	    }
	    $app->container->share($app->router);
	}
};
?>