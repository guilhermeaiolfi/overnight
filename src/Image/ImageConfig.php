<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Drivers\Gd\Driver;
use ON\Config\Config;
use ON\FS\DirectoryPathInterface;
use ON\FS\PathFolder;

class ImageConfig extends Config
{
	private ?DirectoryPathInterface $publicImagesDir = null;

	public static function getDefaults(): array
	{
		return [
			"publicImagesDir" => "i",
			"404ImagePath" => "404i.png",
			"templates" => [
				"custom" => CustomTemplate::class,
			],
			"driver" => Driver::class,
		];
	}

	public function getPublicImagesDir(): DirectoryPathInterface
	{
		if ($this->publicImagesDirFile !== null) {
			return $this->publicImagesDirFile;
		}

		$configured = $this->get('publicImagesDir', 'i');

		if ($configured instanceof DirectoryPathInterface) {
			$this->publicImagesDir = $configured;
		} else {
			$this->publicImagesDir = PathFolder::from(trim((string) $configured, '/\\'));
			$this->set('publicImagesDirFile', $this->publicImagesDir);
		}

		return $this->publicImagesDir;
	}

	public function getPublicImagesUri(): string
	{
		return trim(str_replace('\\', '/', $this->get('publicImagesDir', 'i')), "/");
	}
}
