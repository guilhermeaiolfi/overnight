<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Exception;
use Intervention\Image\ImageManager as InterventionManager;
use Intervention\Image\Interfaces\ModifierInterface;
use ON\FS\DirectoryPathInterface;
use ON\FS\FilePathInterface;
use ON\FS\PathFile;
use ON\FS\PublicAsset;
use ON\FS\PublicAssetInterface;
use ON\Image\ImageConfig;

class FileSystem implements ImageCacheInterface
{
	public function __construct(
		protected ImageConfig $config,
		protected ?DirectoryPathInterface $publicRoot = null
	) {
	}

	public function create(string $token, callable|ModifierInterface $template, string|FilePathInterface $sourcePath): PublicAssetInterface
	{
		// image manipulation based on callback
		$driver_class = $this->config["driver"];

		if (! class_exists($driver_class)) {
			throw new Exception("{$driver_class} doesn't exists. Consider installing the 'intervention/image' package.");
		}
		$driver = new $driver_class();

		$manager = new InterventionManager($driver);
		$cachedPublicAsset = $this->get($sourcePath, $token);
		$cacheFile = $cachedPublicAsset->getFile();
		$cacheDirectory = $cacheFile->getParent()->getAbsolutePath();

		@mkdir($cacheDirectory, 0777, true);

		$resolvedSourcePath = $sourcePath instanceof FilePathInterface ? $sourcePath->getAbsolutePath() : (string) $sourcePath;
		$manager->read($resolvedSourcePath)->modify($template)->save($cacheFile->getAbsolutePath());

		return $cachedPublicAsset;
	}

	public function get(string|FilePathInterface $sourcePath, string $token): PublicAssetInterface
	{
		$filepath = $sourcePath instanceof FilePathInterface ? $sourcePath->getFilename() : basename((string) $sourcePath);
		$dotPos = strrpos($filepath, '.');
		$extension = $dotPos !== false ? substr($filepath, $dotPos + 1) : 'jpg';

		$basePath = $this->config->getPublicImagesDir()->getAbsolutePath();
		
		$cachedFile = substr($token, 0, 4) . "/" . substr($token, 4, strlen($token)) . "." . $extension;
		$publicPath = $basePath . "/" . $cachedFile;

		$uri = $this->config->getPublicImagesUri() . '/' . $cachedFile;
		return new PublicAsset($uri, $this->cacheFile($publicPath));
	}

	public function extractToken(string $uri): string
	{
		return str_replace("/", "", $uri);
	}

	private function cacheFile(string $filename): PathFile
	{
		if ($this->publicRoot === null) {
			throw new Exception('FileSystem cache requires a public root directory.');
		}

		return PathFile::from($filename, $this->publicRoot);
	}
}
