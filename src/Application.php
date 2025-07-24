<?php

declare(strict_types=1);

namespace ON;

use Exception;
use ON\Extension\ExtensionInterface;
use Symfony\Component\Dotenv\Dotenv;

class Application
{
	public static ?self $instance = null;

	protected string $project_dir;

	protected array $extensionsToInstall = [];

	protected array $aliases = [];

	protected array $extensions = [];

	protected array $setupingExtensions = [];

	protected array $__methods = [];

	protected array $__properties = [];

	protected bool $debug = true;

	protected string $environment = "development";

	public ?Dotenv $env = null;

	/**
	 * @param array {
	 *   debug?: ?bool,
	 *   env?: ?string,
	 *   disable_dotenv?: ?bool,
	 *   project_dir?: ?string,
	 *   prod_envs?: ?string[],
	 *   dotenv_path?: ?string,
	 *   test_envs?: ?string[],
	 *   use_putenv?: ?bool,
	 *   runtimes?: ?array,
	 *   error_handler?: string|false,
	 *   env_var_name?: string,
	 *   debug_var_name?: string,
	 *   dotenv_overload?: ?bool,
	 * } $options
	 */
	public function __construct(
		protected ?array $options = []
	) {

		if (! isset(self::$instance)) {
			self::$instance = $this;
		}

		$this->project_dir = $project_dir = $options["project_dir"] ?? dirname(getcwd(), 1);


		// defines the default dir to the project root
		if ($project_dir != getcwd()) {
			chdir($project_dir);
		}

		$extensions = $options["extensions"] ?? "./config/extensions.php";

		if (is_string($extensions)) {
			$extensions = require_once($extensions);
		}

		// load .env file vars
		$this->env = new Dotenv();
		$this->env->load('.env');


		$this->debug = $_ENV["APP_DEBUG"] = $options["debug"] ?? "true" === $_ENV["APP_DEBUG"];

		$this->environment = $_ENV["APP_ENV"] ?? "development";

		foreach ($extensions as $ext_class => $ext_options) {
			if (isset($ext_options["enabled"])) {
				if (is_callable($ext_options["enabled"])) {
					$ext_options["enabled"] = $ext_options["enabled"]($this);
				}
			} else {
				$ext_options["enabled"] = true;
			}

			if ($ext_options["enabled"]) {
				$this->extensionsToInstall[$ext_class] = $ext_options;
			}
		}

		$this->loadExtensions();
	}

	public function isDebug(): bool
	{
		return $this->debug;
	}

	public function getEnvironment(): string
	{
		return $this->environment;
	}

	public function isCli(): bool
	{
		return php_sapi_name() == 'cli';
	}

	/**
	 * @template T
	 * @param class-string<T> $name
	 * @return T
	 */
	public function ext(string $name)
	{
		return $this->getExtension($name);
	}

	public function getInstalledExtensions()
	{
		return array_keys($this->extensions);
	}

	public function isExtensionReady(string $ext_name_or_class): bool
	{
		$ext = $this->getExtension($ext_name_or_class);

		return $ext->isReady();
	}

	protected function loadExtensions()
	{
		$this->setupingExtensions = [];
		foreach ($this->extensionsToInstall as $ext_class => $ext_options) {
			if ($this->install($ext_class, $ext_options)) {
				$this->setupingExtensions[] = $ext_class;
			}
		}

		foreach ($this->extensions as $ext_class => $ext_instance) {
			if ($this->isDebug()) {
				$deps = $ext_instance->requires();
				foreach ($deps as $dep) {
					if (! $this->hasExtension($dep)) {
						throw new Exception("Extension {$ext_class} depends on: \'{$dep}\' that is not installed.");

						return;
					}
				}
			}
			$ext_instance->boot();
		}

		$nextTickQueue = [];
		while ($ext_class = array_shift($this->setupingExtensions)) {
			$ext_instance = $this->extensions[$ext_class];

			if ($ext_instance) {
				$ext_instance->setState('setup');
				$ext_instance->setup();
				if ($ext_instance->getNextTick()) {
					$nextTickQueue[] = $ext_class;
				}
			}
		}

		while ($ext_class = array_shift($nextTickQueue)) {
			$ext_instance = $this->extensions[$ext_class];

			if ($ext_instance) {
				$nextTick = $ext_instance->getNextTick();
				$ext_instance->clearNextTick();
				$nextTick();
				if ($ext_instance->getNextTick()) {
					$nextTickQueue[] = $ext_class;
				}
			}
		}
	}

	public function install(string $extension_class, array $extension_options): mixed
	{

		if (! class_exists($extension_class)) {
			throw new Exception("Class {$extension_class} not found when loading as extension");
		}
		$interfaces = class_implements($extension_class);

		if (! isset($interfaces['ON\Extension\ExtensionInterface'])) {
			throw new Exception("Extensions must implement \ON\Extension\ExtensionInterface.");
		}

		$instance = $extension_class::install($this, $extension_options);
		if ($instance !== false) {
			$this->extensions[$extension_class] = $instance;

			return true;
		}

		return false;
	}

	public function registerExtension(string $name_or_class, ExtensionInterface $instance): void
	{
		$this->aliases[$name_or_class] = $instance;
	}

	public function hasExtension(string $name_or_class): bool
	{
		return array_key_exists($name_or_class, $this->extensions) || array_key_exists($name_or_class, $this->aliases);
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @return T
	 */
	public function getExtension(string $className): ExtensionInterface
	{
		if (! $this->hasExtension($className)) {
			throw new Exception("Extension {$className} is not installed.");
		}

		return $this->extensions[$className] ?? $this->aliases[$className];
	}

	public function __get($name)
	{
		return $this->__properties[$name];
	}

	public function __set($name, $value)
	{
		if (isset($this->__properties[$name])) {
			throw new Exception("It's not possible to overwrite properties already defined.");
		}
		$this->__properties[$name] = $value;
	}

	public function __call($name, $args)
	{
		return call_user_func_array($this->__methods[$name], $args);
	}

	public function registerMethod($name, callable $method)
	{
		$this->__methods[$name] = $method;
	}

	public function run()
	{
		if (! isset($this->__methods["run"])) {
			throw new Exception("There is no extension defining the run method.");
		}
		call_user_func($this->__methods["run"]);
	}
}
