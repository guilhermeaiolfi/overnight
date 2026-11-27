<?php

declare(strict_types=1);

namespace ON\Session\Container;

use function class_exists;
use ON\Container\ApplicationBinding;
use ON\FS\Path;
use ON\Session\Native\NativeSession;
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

		$savePath = $options['save_path'] ?? null;
		if (is_a($implementation, NativeSession::class, true)
			&& ($options['save_handler'] ?? session_module_name()) === 'files'
			&& is_string($savePath)
			&& ($app = ApplicationBinding::get($container)) !== null
		) {
			// PHP supports N;MODE;path as well as a plain session directory.
			$parts = explode(';', $savePath);
			$directory = array_pop($parts);
			if (trim($directory) !== '') {
				$parts[] = Path::from($directory, $app->paths->get('project'))->getAbsolutePath();
				$options['save_path'] = implode(';', $parts);
			}
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
