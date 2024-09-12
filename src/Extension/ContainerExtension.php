<?php

namespace ON\Extension;

use Exception;
use Laminas\Stratigility\Middleware\ErrorHandler;
use ON\Application;

class ContainerExtension implements ExtensionInterface
{
 
    protected $container;
    public function __construct(
        protected Application $app
    ) {

        /** @var \ON\Extension\ConfigExtension $config_ext */
        $config_ext = $app->getExtension(ConfigExtension::class);

        /** @var \Psr\Container\ContainerInterface $container */

        $this->container = $container = (require_once 'config/container.php')($config_ext->getConfig());

        //creates the error handler early on so anything gets handled
        $errorHandler = $container->get(ErrorHandler::class);

        Application::setContainer($container);

        // we need to set it to the container in case other places need the instance of Application
        $container->set(get_class($app), $app);
    }

    public static function install(Application $app): mixed {
        $extension = new self($app);
        return $extension;
    }
}
