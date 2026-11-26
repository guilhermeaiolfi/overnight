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
		$key = $imageCfg->get('key');
		if (! is_string($key) || trim($key) === '') {
			$key = isset($_ENV['APP_SALT']) && is_string($_ENV['APP_SALT']) ? $_ENV['APP_SALT'] : '';
		}
		if (trim($key) === '') {
			throw new RuntimeException(
				'No image signing key configured. Set "key" in ImageConfig or APP_SALT in the environment.'
			);
		}
		$encrypter = new $encrypter_class($key, $imageCfg->get('encrypter.options'));
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
