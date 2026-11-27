<?php

declare(strict_types=1);

namespace ON\Composer;

use Composer\Json\JsonFile;
use Composer\Package\Package;
use Composer\Script\Event;
use Composer\Util\Filesystem;

final class LocalOnData
{
	private const PACKAGE = 'guilhermeaiolfi/overnight-data';

	public static function configure(Event $event): void
	{
		if (! $event->isDevMode() || getenv('CI')) {
			return;
		}

		$checkout = realpath(dirname(__DIR__, 3) . '/ondata');
		if ($checkout === false || ! is_file($checkout . '/composer.json')) {
			return;
		}

		$manifest = (new JsonFile($checkout . '/composer.json'))->read();
		if (($manifest['name'] ?? null) !== self::PACKAGE || ! is_array($manifest['autoload'] ?? null)) {
			return;
		}

		$composer = $event->getComposer();
		foreach ($composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
			if (! $package instanceof Package || $package->getName() !== self::PACKAGE) {
				continue;
			}

			$installed = $composer->getInstallationManager()->getInstallPath($package);
			$filesystem = new Filesystem();
			$path = $filesystem->findShortestPath($installed, $checkout, directories: true, preferRelative: true);
			if ($filesystem->isAbsolutePath($path)) {
				return;
			}

			// Composer resolves dependency autoload paths relative to the installed package.
			$autoload = $manifest['autoload'];
			array_walk_recursive($autoload, static function (string &$entry) use ($path): void {
				$entry = $path . '/' . ltrim($entry, '/\\');
			});
			$package->setAutoload($autoload);
			$event->getIO()->writeError('<info>Loading ON Data from ../ondata</info>');

			return;
		}
	}
}
