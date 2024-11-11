<?php
namespace ON\Session;

use Psr\Container\ContainerInterface;
use Psr\Container\Exception\ContainerException;
use Laminas\Session\Config\ConfigInterface;
use Laminas\Session\Config\SessionConfig;
use Laminas\Session\Container;
use Laminas\Session\SaveHandler\SaveHandlerInterface;
use Laminas\Session\SessionManager;
use Laminas\Session\Storage\StorageInterface;


class SessionManagerFactory
{
    protected $container;

    public function __construct (ContainerInterface $container) {
      $this->container = $container;
    }

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke()
    {
        $config = $this->container->get('config');
        if (!isset($config['session'])) {
            $sessionManager = new SessionManager();
            Container::setDefaultManager($sessionManager);
            return $sessionManager;
        }
        $session = $config['session'];
        $sessionConfig = null;
        if (isset($session['config'])) {
            $class = isset($session['config']['class'])
                ? $session['config']['class']
                : SessionConfig::class;
            $options = isset($session['config']['options'])
                ? $session['config']['options']
                : [];
            $sessionConfig = new $class();
            if (!($sessionConfig instanceof ConfigInterface)) {
                throw new \InvalidArgumentException(
                    'Session config must implement Laminas\Session\Config\ConfigInterface.'
                );
            }
            $sessionConfig->setOptions($options);
        }
        $sessionStorage = null;
        if (isset($session['storage'])) {
            $class = $session['storage'];
            if (is_string($session['storage'])) {
                $class = new $session['storage']();
            }
            if (!($class instanceof StorageInterface)) {
                throw new \InvalidArgumentException(
                    'Session storage must implement Laminas\Session\Storage\StorageInterface.'
                );
            }
            $sessionStorage = $class;
        }
        $sessionSaveHandler = null;
        if (isset($session['save_handler'])) {
            $class = $session['save_handler'];
            if (is_string($session['save_handler'])) {
                // class should be fetched from service manager
                // since it will require constructor arguments
                $class = $this->container->get($session['save_handler']);
            }
            if (!($class instanceof SaveHandlerInterface)) {
                throw new \InvalidArgumentException(
                    'Session save handler must implement Laminas\Session\SaveHandler\SaveHandlerInterface.'
                );
            }
            $sessionSaveHandler = $class;
        }
        $sessionManager = new SessionManager(
            $sessionConfig,
            $sessionStorage,
            $sessionSaveHandler,
            isset($session['validators'])
                ? $session['validators']
                : []
        );
        Container::setDefaultManager($sessionManager);
        return $sessionManager;
    }
}