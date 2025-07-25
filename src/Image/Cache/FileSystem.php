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


		$pathinfo = pathinfo($filename);

		@mkdir("public/" . $pathinfo["dirname"], 0777, true);

		$manager->read($path)->modify($template)->save("public/" . $filename);

		return file_get_contents("public/" . $filename);
	}

	public function filename($path, $token)
	{
		$folders = explode("/", $path);

		$filepath = array_pop($folders);
		//$filepath = basename($path);
		list($name, $extension) = explode(".", $filepath);

		return $this->config['basePath'] . substr($token, 0, 4) . "/" . substr($token, 4, strlen($token)) . "." . $extension;
	}

	public function token($token)
	{
		return str_replace("/", "", $token);
	}
}
