<?php

declare(strict_types=1);

namespace ON;

use Exception;
use ON\FS\PathRegistry;
use ON\Extension\ExtensionInterface;
use ON\Init\Init;
use Symfony\Component\Dotenv\Dotenv;

class Application
{
	public static ?self $instance = null;

	protected array $extensionsToInstall = [];

	protected array $aliases = [];

	protected array $extensions = [];

	protected array $__methods = [];

	protected array $__properties = [];

	protected bool $debug = true;

	protected string $environment = "development";

	public ?Dotenv $env = null;

	public PathRegistry $paths;

	protected Init $init;

	/** @var array<string, array<string, callable>> [MethodName => [ExtensionClass => Callback]] */
	protected array $unresolvedMethods = [];

	/**
	 * @param array {
	 *   debug?: ?bool,
	 *   env?: ?string,
	 *   disable_dotenv?: ?bool,
	 *   paths?: ?array<string, string>,
	 *   prod_envs?: ?string[],
	 *   dotenv_path?: ?string,
	 *   test_envs?: ?string[],
	 *   use_putenv?: ?bool,
	 *   runtimes?: ?array,
	 *   error_handler?: string|false,
	 *   env_var_name?: string,
	 *   debug_var_name?: string,
	 *   dotenv_overload?: ?bool,
	 *   error_reporting?: ?int,
	 * } $options
	 */
	public function __construct(
		protected ?array $options = []
	) {

		if (! isset(self::$instance)) {
			self::$instance = $this;
		}

		$this->init = new Init();

		$paths = $options['paths'] ?? [];
		if (! is_array($paths)) {
			throw new Exception('Application option "paths" must be an array.');
		}

		$paths['project'] ??= dirname(getcwd(), 1);

		$this->paths = new PathRegistry($paths, getcwd());
		$project_dir = $this->paths->get('project')->getAbsolutePath();

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

		$debug = $options["debug"] ?? $_ENV["APP_DEBUG"] ?? $_SERVER["APP_DEBUG"] ?? false;
		$this->debug = $_ENV["APP_DEBUG"] = filter_var($debug, FILTER_VALIDATE_BOOLEAN);

		$this->configureErrorDisplay();

		$this->environment = $_ENV["APP_ENV"] ?? "development";

		foreach ($extensions as $ext_class => $ext_options) {
			$this->extensionsToInstall[$ext_class] = $ext_options;
		}

		$this->loadExtensions();
	}

	protected function configureErrorDisplay(): void
	{
		ini_set('display_errors', $this->debug ? '1' : '0');
		ini_set('display_startup_errors', $this->debug ? '1' : '0');
		error_reporting($this->options["error_reporting"] ?? E_ALL);
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

	public function init(): Init
	{
		return $this->init;
	}

	protected function loadExtensions()
	{
		// Phase 1: Instantiate all extensions (Order doesn't matter yet)
		foreach ($this->extensionsToInstall as $ext_class => $ext_options) {
			$this->install($ext_class, $ext_options);
		}

		// Phase 2: Registration Pass (Collecting all subscriptions and methods)
		foreach ($this->extensions as $class => $instance) {
			$this->init->setCurrentExtension($class);
			$instance->register($this->init);
		}
		$this->init->setCurrentExtension(null);

		$subscriptionMap = $this->init->getSubscriptionMap();

		// Phase 3: Lifecycle Order (Hash Check or Rebuild)
		$orderedClasses = $this->getLifecycleOrder($subscriptionMap);


		// Phase 4: Sorting & Resolution (Deferred execution logic)
		$this->init->sortListeners($orderedClasses);
		$this->resolveMethods($orderedClasses);

		// Phase 5: Execution (Start in sorted order)
		foreach ($orderedClasses as $class) {
			$ext_instance = $this->extensions[$class];
			if (method_exists($ext_instance, 'start')) {
				$ext_instance->start($this->init->context());
			}
		}
	}

	/** @return string[] Array of ordered extension classes */
	protected function getLifecycleOrder(array $subscriptionMap): array
	{
		$hash = md5(serialize($subscriptionMap));
		$cacheFile = $this->paths->get('cache')->append('app_lifecycle.php')->getAbsolutePath();

		if (!$this->debug && file_exists($cacheFile)) {
			$cache = require $cacheFile;
			if ($cache['hash'] === $hash) {
				return $cache['order'];
			}
		}

		// Cache miss or debug mode: Heavy Lifting
		$order = $this->rebuildLifecycleOrder($subscriptionMap);

		if (!$this->debug) {
			if (!is_dir(dirname($cacheFile))) {
				mkdir(dirname($cacheFile), 0777, true);
			}
			$content = "<?php\n\nreturn " . var_export(['hash' => $hash, 'order' => $order], true) . ";\n";
			file_put_contents($cacheFile, $content);
		}

		return $order;
	}

	protected function resolveMethods(array $orderedClasses): void
	{
		foreach ($this->unresolvedMethods as $name => $registrations) {
			if (count($registrations) > 1) {
				$owners = implode(', ', array_keys($registrations));
				throw new Exception("Conflict detected: The method '{$name}' was registered by multiple extensions: [{$owners}]. Only one extension can define a specific method.");
			}

			$owner = array_key_first($registrations);
			$this->__methods[$name] = $registrations[$owner];
		}
	}

	/**
	 * @param array<string, string[]> $subscriptionMap [ListenerClass => [EventClasses]]
	 * @return string[] Ordered list of extension classes
	 */
	protected function rebuildLifecycleOrder(array $subscriptionMap): array
	{
		$graph = [];
		$extensionNamespaces = [];

		// Build a map of NamespaceRoot => ExtensionClass
		foreach ($this->extensions as $class => $inst) {
			$parts = explode('\\', $class);
			array_pop($parts); // Remove class name
			$ns = implode('\\', $parts);
			$extensionNamespaces[$ns] = $class;
			$graph[$class] = [];
		}

		// Infer dependencies from event namespaces
		foreach ($subscriptionMap as $listenerClass => $events) {
			foreach ($events as $eventClass) {
				$ownerClass = $this->inferEventOwner($eventClass, $extensionNamespaces);
				if ($ownerClass && $ownerClass !== $listenerClass) {
					$graph[$listenerClass][] = $ownerClass;
				}
			}
		}

		// Topological sort
		$ordered = [];
		$visiting = [];
		$visited = [];

		foreach (array_keys($this->extensions) as $class) {
			$this->sortExtension($class, $graph, $ordered, $visiting, $visited);
		}


		return $ordered;
	}

	private function inferEventOwner(string $eventClass, array $extensionNamespaces): ?string
	{
		$parts = explode('\\', $eventClass);
		while (count($parts) > 1) {
			array_pop($parts);
			$ns = implode('\\', $parts);
			if (isset($extensionNamespaces[$ns])) {
				return $extensionNamespaces[$ns];
			}
		}
		return null;
	}

	private function sortExtension(
		string $class,
		array $graph,
		array &$ordered,
		array &$visiting,
		array &$visited
	): void {
		if (isset($visited[$class])) {
			return;
		}

		if (isset($visiting[$class])) {
			throw new Exception("Circular extension dependency detected for {$class}.");
		}

		$visiting[$class] = true;

		foreach ($graph[$class] as $dependency) {
			$this->sortExtension($dependency, $graph, $ordered, $visiting, $visited);
		}

		unset($visiting[$class]);
		$visited[$class] = true;
		$ordered[] = $class;
	}

	public function install(string $extension_class, array $extension_options): ?ExtensionInterface
	{

		if (isset($extension_options["enabled"])) {
			if (is_callable($extension_options["enabled"])) {
				$extension_options["enabled"] = $extension_options["enabled"]($this);
			}

			if (! $extension_options["enabled"]) {
				return null;
			}

			unset($extension_options["enabled"]);
		}

		if ($this->hasExtension($extension_class)) {
			throw new Exception("Extension {$extension_class} is already installed.");
		}

		if (! class_exists($extension_class)) {
			throw new Exception("Class {$extension_class} not found when loading as extension");
		}

		if ($this->isDebug()) {
			$interfaces = class_implements($extension_class);

			if (! isset($interfaces['ON\Extension\ExtensionInterface'])) {
				throw new Exception("Extensions must implement \ON\Extension\ExtensionInterface.");
			}
		}

		$instance = new $extension_class($this, $extension_options);
		if (isset($instance)) {
			$this->extensions[$extension_class] = $instance;
			$this->registerExtension($instance->id(), $instance);
			$this->registerExtensionShortcut($instance);

			return $instance;
		}

		return null;
	}

	private function registerExtensionShortcut(ExtensionInterface $instance): void
	{
		$id = $instance->id();

		if (! isset($this->__properties[$id])) {
			$this->__properties[$id] = $instance;
		}
	}

	public function registerExtension(string $name_or_class, ExtensionInterface $instance): void
	{
		if (isset($this->aliases[$name_or_class]) && $this->aliases[$name_or_class] !== $instance) {
			throw new Exception("Extension alias {$name_or_class} is already registered.");
		}

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
		$owner = $this->init->getCurrentExtension();
		$this->unresolvedMethods[$name][$owner] = $method;
	}

	public function run()
	{
		if (! isset($this->__methods["run"])) {
			throw new Exception("There is no extension defining the run method.");
		}
		call_user_func($this->__methods["run"]);
	}
}
