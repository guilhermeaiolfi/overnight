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

	protected $cachefile = "var/cache/discovery/attributes.cache.php";
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

	public function cachedTimestamp(): float
	{
		return $this->timestamp > 0 ?
			$this->timestamp :
			(file_exists($this->cachefile) ?
				filemtime($this->cachefile) : 0);
	}

	public function process(): bool
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

	public function handle($file): bool
	{
		$classes = $this->classFinder->getClassesInFile($file->getRealPath());
		foreach ($classes as $className) {
			if (preg_match('/(.*)Page$/', $className)) {
				$class = new ReflectionClass($className);
				$this->reader->load($class);
				$this->dirty = true;
			}
		}

		return true;
	}

	public function getAttributes(): AttributeReader
	{
		return $this->reader;
	}

	public function recover(): bool
	{

		$data = file_get_contents($this->cachefile);
		$this->reader = unserialize($data);

		return true;
	}

	public function isDirty(): bool
	{
		return $this->dirty;
	}

	public function save(): bool
	{
		if ($this->isDirty()) {
			@mkdir(dirname($this->cachefile), 0777, true);
			file_put_contents($this->cachefile, serialize($this->reader));

			return true;
		}

		return false;
	}

	public function forget(): void
	{
		if (file_exists($this->cachefile)) {
			unlink($this->cachefile);
		}
	}
}
