<?php

declare(strict_types=1);

namespace ON\Discovery;

use ON\Application;
use ON\Config\AppConfig;
use ON\Config\Scanner\AttributeReader;
use ON\Extension\DiscoveryExtension;
use ReflectionClass;

class AttributesDiscoverer implements DiscoverInterface
{
	public AttributeReader $reader;

	protected $cachefile = "var/cache/discovery/attributes.cache.php";
	protected ClassFinder $classFinder;
	protected bool $changed = false;
	protected AppConfig $config;

	protected array $processors = [ ];
	protected float $timestamp = 0;

	public function __construct(
		protected Application $app,
	) {
		$this->reader = new AttributeReader();
		$this->classFinder = $app->ext(DiscoveryExtension::class)->classFinder;
		$this->config = $app->config->get(AppConfig::class);
		$this->processors = array_keys($this->config->get('discovery.discoverers.' . self::class . '.processors', []));
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
		foreach ($this->processors as $processor) {
			$processor = new $processor($this->app);
			$processor($this->reader);
		}

		return true;
	}

	public function updateFile($file): bool
	{
		$classes = $this->classFinder->getClassesInFile($file->getRealPath());
		foreach ($classes as $className) {
			if (preg_match('/(.*)Page$/', $className)) {
				$class = new ReflectionClass($className);
				$this->reader->load($class);
				$this->changed = true;
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

	public function save(): bool
	{
		if ($this->changed) {
			@mkdir(dirname($this->cachefile), 0777, true);
			file_put_contents($this->cachefile, serialize($this->reader));

			return true;
		}

		return false;
	}
}
