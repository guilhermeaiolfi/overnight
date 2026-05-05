<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Drivers\Gd\Driver;
use ON\Config\Config;
use ON\FS\DirectoryPathInterface;
use ON\FS\PathFolder;

class ImageConfig extends Config
{
	public string|DirectoryPathInterface $publicImagesDir = 'i';
	public string $placeholderImageClass = DefaultPlaceholderImage::class;
	public array $placeholderImageOptions = [
		'width' => 400,
		'height' => 300,
		'background' => '#f3f4f6',
		'foreground' => '#9ca3af',
		'label' => '404',
		'showFilename' => false,
		'templates' => [],
	];
	public array $templates = [
		'custom' => CustomTemplate::class,
	];
	public string $driver = Driver::class;

	private ?DirectoryPathInterface $resolvedPublicImagesDir = null;

	public function getPublicImagesDir(): DirectoryPathInterface
	{
		if ($this->resolvedPublicImagesDir !== null) {
			return $this->resolvedPublicImagesDir;
		}

		$configured = $this->publicImagesDir;

		if ($configured instanceof DirectoryPathInterface) {
			$this->resolvedPublicImagesDir = $configured;
		} else {
			$this->resolvedPublicImagesDir = PathFolder::from(trim((string) $configured, '/\\'));
		}

		return $this->resolvedPublicImagesDir;
	}

	public function getPublicImagesUri(): string
	{
		$configured = $this->publicImagesDir;
		if ($configured instanceof DirectoryPathInterface) {
			return trim(str_replace('\\', '/', $configured->getPath()), '/');
		}

		return trim(str_replace('\\', '/', (string) $configured), '/');
	}
}
