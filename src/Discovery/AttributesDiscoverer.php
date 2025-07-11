<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Scanner\AttributeReader;
use ReflectionClass;

class AttributesDiscoverer implements DiscoverInterface
{
	public AttributeReader $reader;

	protected ClassFinder $classFinder;
	protected bool $dirty = false;
	protected AppConfig $config;

	protected array $processors = [ ];
	protected float $timestamp = 0;

	public function __construct(
		protected Application $app,
	) {
		$this->reader = new AttributeReader();
		$this->classFinder = $app->discovery->classFinder;
		$this->config = $app->config->get(AppConfig::class);
		$this->processors = $this->config->get('discovery.discoverers.' . self::class . '.processors', []);
	}

	public function apply(): bool
	{
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

	public function discover($file): void
	{
		$classes = $this->classFinder->getClassesInFile($file->getRealPath());
		foreach ($classes as $className) {
			if (preg_match('/(.*)Page$/', $className)) {
				$class = new ReflectionClass($className);
				$this->reader->load($class);
				$this->dirty = true;
			}
		}
	}

	public function isDirty(): bool
	{
		return $this->dirty;
	}

	public function getData(): mixed
	{
		return $this->reader;
	}

	public function setData(mixed $data): void
	{
		$this->reader = $data;

	}
}
