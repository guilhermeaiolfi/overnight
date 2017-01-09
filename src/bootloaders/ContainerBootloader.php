<?php 
namespace ON\bootloaders;

use \Auryn\Provider;
class ContainerProvider extends \Auryn\Provider {
	public function get($id) {
	  return $this->make($id);
	}
};
class ContainerBootloader {
	public function __invoke (\ON\Application $app) {
		$container = new ContainerProvider();
		$container->share($container);

		$container->alias('Container', 'ContainerProvider');
		$container->alias('Router', '\ON\Router');
		$container->alias('Context', '\ON\Context');
		$container->alias('Application', '\ON\Application');
		$container->alias('Renderer', '\ON\view\Renderer');
		$container->alias('Dispatcher', '\Relay\RelayBuilder');
		$container->alias('ControllerResolver', '\ON\controller\ControllerResolver');
		$container->define('\ON\controller\ControllerResolver', ['container' => "Container"]);
		$container->delegate('Router', ['\Aura\Router\RouterFactory', 'newInstance']);

		$container->share($app);
		$app->container = $container;
	}
};
?>