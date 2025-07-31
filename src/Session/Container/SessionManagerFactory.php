<?php

declare(strict_types=1);

namespace ON\Session\Container;

use InvalidArgumentException;
use Laminas\Session\Config\ConfigInterface;
use Laminas\Session\Config\SessionConfig as LaminasSessionConfig;
use Laminas\Session\Container;
use Laminas\Session\SaveHandler\SaveHandlerInterface;
use Laminas\Session\SessionManager;
use Laminas\Session\Storage\SessionArrayStorage;
use Laminas\Session\Storage\StorageInterface;
use ON\Session\SessionConfig;
use Psr\Container\ContainerInterface;
use Psr\Container\Exception\ContainerException;

class SessionManagerFactory
{
	public function __construct(
		protected ContainerInterface $container,
		protected SessionConfig $sessionConfig
	) {
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
		if (! isset($this->sessionConfig)) {
			$sessionManager = new SessionManager();
			Container::setDefaultManager($sessionManager);

			return $sessionManager;
		}
		$laminasSessionConfig = null;
		if (isset($this->sessionConfig)) {
			$class = $this->sessionConfig->get('config.class', LaminasSessionConfig::class);
			$options = $this->sessionConfig->get('config.options', []);

			$laminasSessionConfig = new $class();
			if (! ($laminasSessionConfig instanceof ConfigInterface)) {
				throw new InvalidArgumentException(
					'Session config must implement Laminas\Session\Config\ConfigInterface.'
				);
			}
			$laminasSessionConfig->setOptions($options);
		}

		$storageClass = $this->sessionConfig->get('storage', SessionArrayStorage::class);

		$sessionStorage = new $storageClass();

		if (! ($sessionStorage instanceof StorageInterface)) {
			throw new InvalidArgumentException(
				'Session storage must implement Laminas\Session\Storage\StorageInterface.'
			);
		}

		$sessionSaveHandlerClass = $this->sessionConfig->get('save_handler');
		$sessionSaveHandler = null;
		if (isset($sessionSaveHandlerClass)) {
			// class should be fetched from service manager
			// since it will require constructor arguments
			$sessionSaveHandler = $this->container->get($sessionSaveHandlerClass);

			if (! ($sessionSaveHandler instanceof SaveHandlerInterface)) {
				throw new InvalidArgumentException(
					'Session save handler must implement Laminas\Session\SaveHandler\SaveHandlerInterface.'
				);
			}
		}

		$sessionManager = new SessionManager(
			$laminasSessionConfig,
			$sessionStorage,
			$sessionSaveHandler,
			$this->sessionConfig->get('validators', [])
		);
		Container::setDefaultManager($sessionManager);

		return $sessionManager;
	}
}
