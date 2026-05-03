<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Exception;
use Intervention\Image\ImageManager as InterventionManager;
use ON\DirectoryPathInterface;
use ON\FilePathInterface;
use ON\Image\ImageConfig;
use ON\PathFile;

class FileSystem implements ImageCacheInterface
{
	public function __construct(
		protected ImageConfig $config,
		protected ?DirectoryPathInterface $publicRoot = null
	) {
	}

	public function get($token, $template, $path)
	{

		// image manipulation based on callback
		$driver_class = $this->config["driver"];

		if (! class_exists($driver_class)) {
			throw new Exception("{$driver_class} doesn't exists. Consider installing the 'intervention/image' package.");
		}
		$driver = new $driver_class();

		$manager = new InterventionManager($driver);
		$filename = $this->filename($path, $token);
		$cacheFile = $this->cacheFile($filename);
		$cacheDirectory = $cacheFile->parent()->absolute();

		@mkdir($cacheDirectory, 0777, true);

		$sourcePath = $path instanceof FilePathInterface ? $path->absolute() : (string) $path;
		$manager->read($sourcePath)->modify($template)->save($cacheFile->absolute());

		return file_get_contents($cacheFile->absolute());
	}

	public function filename($path, $token)
	{
		$filepath = $path instanceof FilePathInterface ? $path->filename() : basename((string) $path);
		$dotPos = strrpos($filepath, '.');
		$extension = $dotPos !== false ? substr($filepath, $dotPos + 1) : 'jpg';

		$basePath = $this->config->publicImagesUriPath();
		$basePath = $basePath === '' ? '' : $basePath . '/';

		return $basePath . substr($token, 0, 4) . "/" . substr($token, 4, strlen($token)) . "." . $extension;
	}

	public function token($token)
	{
		return str_replace("/", "", $token);
	}

	private function cacheFile(string $filename): PathFile
	{
		if ($this->publicRoot === null) {
			throw new Exception('FileSystem cache requires a public root directory.');
		}

		return PathFile::from($filename, $this->publicRoot);
	}
}
