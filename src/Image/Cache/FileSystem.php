<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Exception;
use Intervention\Image\ImageManager as InterventionManager;
use ON\Image\ImageConfig;

class FileSystem implements ImageCacheInterface
{
	public function __construct(
		protected ImageConfig $config
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
		$basename = basename($path);
		$filename = $this->filename($path, $token);

		$publicPath = rtrim($this->config->get('publicPath', 'public/'), '/') . '/';
		$pathinfo = pathinfo($filename);

		@mkdir($publicPath . $pathinfo["dirname"], 0777, true);

		$manager->read($path)->modify($template)->save($publicPath . $filename);

		return file_get_contents($publicPath . $filename);
	}

	public function filename($path, $token)
	{
		$folders = explode("/", $path);

		$filepath = array_pop($folders);
		$dotPos = strrpos($filepath, '.');
		$extension = $dotPos !== false ? substr($filepath, $dotPos + 1) : 'jpg';

		return $this->config['basePath'] . substr($token, 0, 4) . "/" . substr($token, 4, strlen($token)) . "." . $extension;
	}

	public function token($token)
	{
		return str_replace("/", "", $token);
	}
}
