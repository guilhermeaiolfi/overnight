<?php

declare(strict_types=1);

namespace ON\Image\Container;

use Exception;
use Intervention\Image\ImageManager as InterventionImageManager;
use ON\Image\ImageConfig;
use Psr\Container\ContainerInterface;

class InterventionImageManagerFactory
{
	protected $container;

	public function __construct(ContainerInterface $c)
	{
		$this->container = $c;
	}

	public function __invoke()
	{
		$imageCfg = $this->container->get(ImageConfig::class);

		// image manipulation based on callback
		$driver_class = $imageCfg->get("driver");

		if (! class_exists($driver_class)) {
			throw new Exception("{$driver_class} doesn't exists. Consider installing the 'intervention/image' package.");
		}
		$driver = new $driver_class();

		return new InterventionImageManager($driver);
	}
}
