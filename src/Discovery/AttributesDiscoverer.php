<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Scanner\AttributeReader;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use SplFileInfo;

class AttributesDiscoverer implements DiscoverInterface
{
	public AttributeReader $reader;

	protected bool $dirty = false;
	protected AppConfig $config;

	protected array $processors = [ ];

	protected DiscoveryItems $items;

	public function __construct(
		protected Application $app,
		protected ContainerInterface $container,
		protected ClassFinder $classFinder,
	) {
		$this->reader = new AttributeReader();
		$this->config = $app->config->get(AppConfig::class);
		$this->processors = $this->config->get('discovery.discoverers.' . self::class . '.processors', []);
		$this->items = new DiscoveryItems();
	}

	public function apply(): bool
	{
		//dump($this->items);
		// convert DiscoveryItems into reader cache data
		foreach ($this->items as $item) {
			$attributes = $item->getValue();
			$className = $item->getClassName();
			$this->reader->cacheData($className, $attributes);
		}

		foreach ($this->processors as $className => $options) {
			$processor = new $className($this->app, $this->container, $options);
			$processor($this->reader);
		}

		return true;
	}

	public function addProcessor(string $className, array $options = []): void
	{
		$this->processors[$className] = $options;
	}

	public function discover(SplFileInfo $file, DiscoveryLocation $location): void
	{
		$realPath = $file->getRealPath();
		if ($realPath === false) {
			return;
		}

		// Remove stale references loaded from cache once per file before scanning its classes
		$this->items->removeFromFile($realPath);

		$classes = $this->classFinder->getClassesInFile($realPath);
		foreach ($classes as $className) {
			if (preg_match('/(.*)(Page|Controller)$/', $className)) {
				$class = new ReflectionClass($className);

				$attributes = $this->reader->extractData($class);

				if (count($attributes) > 0) {
					$item = new DiscoveryItem($attributes, $location);
					$item->setFile($realPath);
					$item->setClassName($class->getName());
					$item->setDirty(true);

					$this->items->add($item);
					$this->dirty = true;
				}
			}
		}
	}

	public function getData(): DiscoveryItems
	{
		return $this->items;
	}

	public function addData(DiscoveryItems $items): void
	{
		foreach ($items as $item) {
			$this->items->add($item);
		}
	}

	public function setData(DiscoveryItems $items): void
	{
		$this->items = $items;

	}
}
