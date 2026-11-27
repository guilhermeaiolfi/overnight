<?php

declare(strict_types=1);

namespace ON\Composer;

use Composer\Json\JsonFile;
use Composer\Package\Package;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

final class LocalPackages
{
	private const PACKAGES = [
		'guilhermeaiolfi/overnight',
		'guilhermeaiolfi/overnight-data',
	];

	public static function configure(Event $event): void
	{
		if (! $event->isDevMode() || getenv('CI')) {
			return;
		}

		$root = Platform::getCwd();
		if (! is_dir($root . '/packages')) {
			return;
		}

		$composer = $event->getComposer();
		$filesystem = new Filesystem();
		foreach ($composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
			if (! $package instanceof Package || ! in_array($package->getName(), self::PACKAGES, true)) {
				continue;
			}

			$directory = 'packages/' . basename($package->getName());
			$checkout = $root . '/' . $directory;
			$manifestPath = $checkout . '/composer.json';
			if (! is_file($manifestPath)) {
				continue;
			}

			$manifest = (new JsonFile($manifestPath))->read();
			if (($manifest['name'] ?? null) !== $package->getName() || ! is_array($manifest['autoload'] ?? null)) {
				continue;
			}

			$installed = $composer->getInstallationManager()->getInstallPath($package);
			$path = $filesystem->findShortestPath($installed, $checkout, directories: true, preferRelative: true);
			if ($filesystem->isAbsolutePath($path)) {
				continue;
			}

			// Composer resolves dependency autoload paths relative to the installed package.
			$autoload = $manifest['autoload'];
			array_walk_recursive($autoload, static function (string &$entry) use ($path): void {
				$entry = $path . '/' . ltrim($entry, '/\\');
			});
			$package->setAutoload($autoload);
			$event->getIO()->writeError('<info>Loading ' . $package->getName() . ' from ' . $directory . '</info>');
		}
	}
}
