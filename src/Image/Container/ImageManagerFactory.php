<?php

declare(strict_types=1);

namespace ON\Image\Container;

use ON\Image\Cache\FileSystem;
use ON\Image\Encrypter\OpenSSL;
use ON\Image\ImageConfig;
use ON\Image\ImageManager;
use Psr\Container\ContainerInterface;

class ImageManagerFactory
{
	protected $container;

	public function __construct(ContainerInterface $c)
	{
		$this->container = $c;
	}

	public function __invoke()
	{
		$config = $this->container->get(ImageConfig::class);
		$encrypter_class = $config->get('encrypter.class', OpenSSL::class);
		$encrypter = new $encrypter_class($config->get('key'), $config->get('encrypter.options'));
		$cache_class = $config->get('cache.class', FileSystem::class);
		$cache = new $cache_class($config->get());

		return new ImageManager($config->get(), $encrypter, $cache);
	}
}
