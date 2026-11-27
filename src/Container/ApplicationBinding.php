<?php

declare(strict_types=1);

namespace ON\Container;

use DI\Container;
use ON\Application;
use Psr\Container\ContainerInterface;

final class ApplicationBinding
{
	public static function get(ContainerInterface $container): ?Application
	{
		// PHP-DI's has() also reports autowirable classes without an explicit binding.
		if ($container instanceof Container && ! in_array(Application::class, $container->getKnownEntryNames(), true)) {
			return null;
		}

		return $container->has(Application::class) ? $container->get(Application::class) : null;
	}
}
