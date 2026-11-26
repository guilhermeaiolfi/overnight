<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Exception;
use Intervention\Image\ImageManager as InterventionManager;
use Intervention\Image\Interfaces\ModifierInterface;
use InvalidArgumentException;
use ON\FS\DirectoryPathInterface;
use ON\FS\FilePathInterface;
use ON\FS\PathFile;
use ON\FS\PublicAsset;
use ON\FS\PublicAssetInterface;
use ON\Image\ImageConfig;

class FileSystem implements ImageCacheInterface
{
	public const ALLOWED_EXTENSIONS = [
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'bmp',
	];

	public function __construct(
		protected ImageConfig $config,
		protected ?DirectoryPathInterface $publicRoot = null
	) {
	}

	public function create(string $token, callable|ModifierInterface $template, string|FilePathInterface $sourcePath): PublicAssetInterface
	{
		$cachedPublicAsset = $this->get($sourcePath, $token);
		$cacheFile = $cachedPublicAsset->getFile();

		if (is_file($cacheFile->getAbsolutePath())) {
			return $cachedPublicAsset;
		}

		// image manipulation based on callback
		$driver_class = $this->config['driver'];

		if (! is_string($driver_class) || ! class_exists($driver_class)) {
			throw new Exception("{$driver_class} doesn't exists. Consider installing the 'intervention/image' package.");
		}
		$driver = new $driver_class();

		$manager = new InterventionManager($driver);
		$cacheDirectory = $cacheFile->getParent()->getAbsolutePath();

		if (! is_dir($cacheDirectory) && ! @mkdir($cacheDirectory, 0755, true) && ! is_dir($cacheDirectory)) {
			throw new Exception(sprintf('Unable to create image cache directory "%s".', $cacheDirectory));
		}

		$resolvedSourcePath = $sourcePath instanceof FilePathInterface ? $sourcePath->getAbsolutePath() : (string) $sourcePath;
		$manager->read($resolvedSourcePath)->modify($template)->save($cacheFile->getAbsolutePath());

		return $cachedPublicAsset;
	}

	public function get(string|FilePathInterface $sourcePath, string $token): PublicAssetInterface
	{
		if (
			$token === ''
			|| str_contains($token, "\0")
			|| str_contains($token, '/')
			|| str_contains($token, '\\')
			|| str_contains($token, '..')
		) {
			throw new InvalidArgumentException('Invalid image cache token.');
		}

		$filepath = $sourcePath instanceof FilePathInterface
			? $sourcePath->getFilename()
			: basename(str_replace('\\', '/', (string) $sourcePath));
		$dotPos = strrpos($filepath, '.');
		$rawExtension = $dotPos !== false ? strtolower(substr($filepath, $dotPos + 1)) : '';
		$extension = in_array($rawExtension, self::ALLOWED_EXTENSIONS, true) ? $rawExtension : 'jpg';

		$basePath = $this->config->getPublicImagesDir()->getAbsolutePath();

		$cachedFile = strlen($token) > 4
			? substr($token, 0, 4) . '/' . substr($token, 4) . '.' . $extension
			: $token . '.' . $extension;
		$publicPath = $basePath . '/' . $cachedFile;

		$uri = $this->config->getPublicImagesUri() . '/' . $cachedFile;

		return new PublicAsset($uri, $this->cacheFile($publicPath));
	}

	public function extractToken(string $uri): string
	{
		return str_replace('/', '', $uri);
	}

	private function cacheFile(string $filename): PathFile
	{
		if ($this->publicRoot === null) {
			throw new Exception('FileSystem cache requires a public root directory.');
		}

		return PathFile::from($filename, $this->publicRoot);
	}
}
