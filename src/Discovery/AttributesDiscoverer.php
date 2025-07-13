<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Scanner\AttributeReader;
use ON\Config\Scanner\ReflectionAnalyzableInterface;
use ReflectionClass;

class AttributesDiscoverer implements DiscoverInterface
{
	public AttributeReader $reader;

	protected ClassFinder $classFinder;
	protected bool $dirty = false;
	protected AppConfig $config;

	protected array $processors = [ ];

	protected DiscoveryItems $items;
	public function __construct(
		protected Application $app,
	) {
		$this->reader = new AttributeReader();
		$this->classFinder = $app->discovery->classFinder;
		$this->config = $app->config->get(AppConfig::class);
		$this->processors = $this->config->get('discovery.discoverers.' . self::class . '.processors', []);
		$this->items = new DiscoveryItems();
	}

	public function apply(): bool
	{
		// convert DiscoveryItems into reader cache data
		foreach ($this->items as $item) {
			$attributes = $item->getValue();
			$className = $item->getClassName();
			$this->reader->cacheData($className, $attributes);
		}

		foreach ($this->processors as $className => $options) {
			$processor = new $className($this->app, $options);
			$processor($this->reader);
		}

		return true;
	}

	public function addProcessor(string $className, array $options = []): void
	{
		$this->processors[$className] = $options;
	}

	public function discover($file, DiscoveryLocation $location): void
	{
		$classes = $this->classFinder->getClassesInFile($file->getRealPath());
		foreach ($classes as $className) {
			if (preg_match('/(.*)Page$/', $className)) {
				$class = new ReflectionClass($className);

				$attributes = $this->reader->extractData($class);
				$item = new DiscoveryItem($attributes, $location);
				$item->setFile($file->getRealPath());
				$item->setClassName($class->getName());

				$this->items->add($item);
			}
		}
	}

	public function getData(): mixed
	{
		return $this->items;
	}

	public function addData(mixed $items): void
	{
		foreach ($items as $item) {
			$this->items->add($item);
		}
	}

	public function setData(mixed $items): void
	{
		$this->items = $items;

	}
}
