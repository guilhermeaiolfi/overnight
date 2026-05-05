<?php

declare(strict_types=1);

namespace ON\Image\Container;

use ON\Application;
use ON\Image\Cache\FileSystem;
use ON\Image\Encrypter\OpenSSL;
use ON\Image\ImageConfig;
use ON\Image\ImageManager;
use ON\Image\PlaceholderImageInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

class ImageManagerFactory
{
	protected $container;

	public function __construct(ContainerInterface $c)
	{
		$this->container = $c;
	}

	public function __invoke()
	{
		$imageCfg = $this->container->get(ImageConfig::class);
		$app = $this->container->get(Application::class);
		$encrypter_class = $imageCfg->get('encrypter.class', OpenSSL::class);
		$encrypter = new $encrypter_class($imageCfg->get('key'), $imageCfg->get('encrypter.options'));
		$cache_class = $imageCfg->get('cache.class', FileSystem::class);
		$publicRoot = $app->paths->get('public');
		$cache = $cache_class === FileSystem::class
			? new $cache_class($imageCfg, $publicRoot)
			: new $cache_class($imageCfg);
		$placeholderImageClass = $imageCfg->placeholderImageClass;
		$placeholderImage = new $placeholderImageClass($imageCfg, $cache);

		if (! $placeholderImage instanceof PlaceholderImageInterface) {
			throw new RuntimeException(sprintf(
				'Placeholder image class "%s" must implement %s.',
				$placeholderImageClass,
				PlaceholderImageInterface::class
			));
		}

		return new ImageManager($imageCfg, $app->paths, $encrypter, $cache, $placeholderImage);
	}
}
