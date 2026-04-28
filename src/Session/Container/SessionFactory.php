<?php

declare(strict_types=1);

namespace ON\Session\Container;

use function class_exists;
use ON\Session\SessionConfig;
use ON\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use function sprintf;

class SessionFactory
{
	public function __invoke(ContainerInterface $container): SessionInterface
	{
		$config = $container->get(SessionConfig::class);
		$implementation = $config->get('class');
		/** @var array<string, mixed> $options */
		$options = $config->get('options', []);

		if (! class_exists($implementation)) {
			throw new RuntimeException(sprintf(
				'Configured session implementation "%s" does not exist.',
				$implementation
			));
		}

		$session = new $implementation($options);

		if (! $session instanceof SessionInterface) {
			throw new RuntimeException(sprintf(
				'Configured session implementation "%s" must implement %s.',
				$implementation,
				SessionInterface::class
			));
		}

		return $session;
	}
}
