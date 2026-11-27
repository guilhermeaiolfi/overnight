<?php

declare(strict_types=1);

namespace ON;

use Exception;
use ON\Extension\ExtensionInterface;
use ON\Extension\ExtensionProfiler;
use ON\FS\PathRegistry;
use ON\Init\Init;
use ON\Init\InitAttributeLoader;
use Symfony\Component\Dotenv\Dotenv;

class Application
{
	public static ?self $instance = null;

	protected array $extensionsToInstall = [];

	protected array $aliases = [];

	/** @var array<class-string<ExtensionInterface>, ExtensionInterface> */
	protected array $extensions = [];

	protected bool $debug = true;

	protected string $environment = "development";

	public ?Dotenv $env = null;

	public PathRegistry $paths;

	protected Init $init;

	protected ExtensionProfiler $extensionProfiler;

	/** @var callable|null */
	protected $runner = null;

	protected ?string $runnerOwner = null;

	/**
	 * @param array{
	 *   debug?: ?bool,
	 *   profile?: ?bool,
	 *   env?: ?string,
	 *   disable_dotenv?: ?bool,
	 *   paths?: mixed,
	 *   extensions?: array<class-string<ExtensionInterface>, array>|string|null,
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
	 * }|null $options `paths` accepts a map of path names to strings.
	 */
	public function __construct(
		protected ?array $options = []
	) {

		if (! isset(self::$instance)) {
			self::$instance = $this;
		}

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
			$extensions = require $extensions;
		}

		$envVarName = $options['env_var_name'] ?? 'APP_ENV';
		$debugVarName = $options['debug_var_name'] ?? 'APP_DEBUG';

		// load .env file vars
		if (empty($options['disable_dotenv'])) {
			$this->env = new Dotenv($envVarName, $debugVarName);
			if (isset($options['use_putenv'])) {
				$this->env->usePutenv((bool) $options['use_putenv']);
			}

			$explicitDotenvPath = isset($options['dotenv_path']) && $options['dotenv_path'] !== '';
			$dotenvPath = $explicitDotenvPath ? (string) $options['dotenv_path'] : '.env';

			if ($explicitDotenvPath || file_exists($dotenvPath)) {
				if (! empty($options['dotenv_overload'])) {
					$this->env->overload($dotenvPath);
				} else {
					$this->env->load($dotenvPath);
				}
			}
		}

		$debug = $options["debug"] ?? $_ENV[$debugVarName] ?? $_SERVER[$debugVarName] ?? false;
		$this->debug = $_ENV['APP_DEBUG'] = filter_var($debug, FILTER_VALIDATE_BOOLEAN);

		$this->configureErrorDisplay();

		$environment = $options['env'] ?? $_ENV[$envVarName] ?? $_SERVER[$envVarName] ?? null;
		$this->environment = $environment === null ? 'development' : (string) $environment;
		if ($environment !== null) {
			$_ENV['APP_ENV'] = $this->environment;
		}

		foreach ($extensions as $ext_class => $ext_options) {
			$this->extensionsToInstall[$ext_class] = $ext_options;
		}

		$this->extensionProfiler = new ExtensionProfiler((bool) ($options['profile'] ?? false));
		$this->init = new Init($this->extensionProfiler);

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
	 * @template T of ExtensionInterface
	 * @return ($name is class-string<T> ? T : ExtensionInterface)
	 */
	public function ext(string $name): ExtensionInterface
	{
		return $this->getExtension($name);
	}

	/** @return list<class-string<ExtensionInterface>> */
	public function getInstalledExtensions()
	{
		return array_keys($this->extensions);
	}

	public function init(): Init
	{
		return $this->init;
	}

	public function extensionProfiler(): ExtensionProfiler
	{
		return $this->extensionProfiler;
	}

	protected function loadExtensions()
	{
		// Phase 1: Instantiate all extensions (Order doesn't matter yet)
		foreach ($this->extensionsToInstall as $ext_class => $ext_options) {
			$this->install($ext_class, $ext_options);
		}

		$extensionsHash = md5(serialize(array_keys($this->extensions)));
		$cache = $this->readLifecycleCache();
		$cachedHooks = ($cache !== null && ($cache['extensionsHash'] ?? null) === $extensionsHash && is_array($cache['hooks'] ?? null))
			? $cache['hooks']
			: null;

		$extractedHooks = [];

		// Phase 2: Registration Pass (declarative attributes + imperative register())
		foreach ($this->extensions as $class => $instance) {
			$this->init->setCurrentExtension($class);

			$hooks = $cachedHooks[$class] ?? InitAttributeLoader::extractHooks($instance);
			$extractedHooks[$class] = $hooks;
			$this->init->registerAttributeHooks($instance, $hooks);

			$this->extensionProfiler->begin(
				$class,
				'extension.register',
				'lifecycle',
				[$instance, 'register']
			);

			try {
				$instance->register($this->init);
			} finally {
				$this->extensionProfiler->end();
			}
		}
		$this->init->setCurrentExtension(null);

		$subscriptions = $this->init->getSubscriptions();

		// Phase 3: Lifecycle Order (Cache Check or Rebuild)
		$lifecycle = $this->getLifecycleOrder($subscriptions, $extractedHooks, $extensionsHash, $cache);
		$orderedClasses = $lifecycle['order'];

		// Phase 4: Prune unavailable optional hooks and sort listeners
		$this->init->removeOptionalListenersForEvents($lifecycle['prunedEvents']);
		$this->init->sortListeners($orderedClasses);

		// Phase 5: Execution (Start in sorted order)
		foreach ($orderedClasses as $class) {
			$ext_instance = $this->extensions[$class];
			if (method_exists($ext_instance, 'start')) {
				$this->extensionProfiler->begin(
					$class,
					'extension.start',
					'lifecycle',
					[$ext_instance, 'start']
				);

				try {
					$ext_instance->start($this->init->context());
				} finally {
					$this->extensionProfiler->end();
				}
			}
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function readLifecycleCache(): ?array
	{
		if ($this->debug) {
			return null;
		}

		$cacheFile = $this->paths->get('cache')->append('app_lifecycle.php')->getAbsolutePath();
		if (! file_exists($cacheFile)) {
			return null;
		}

		$cache = require $cacheFile;

		return is_array($cache) ? $cache : null;
	}

	/**
	 * @param array<string, list<array{event: string, optional: bool}>> $subscriptions
	 * @param array<string, list<array{method: string, event: string, stage: string, optional: bool}>> $hooks
	 * @param array<string, mixed>|null $cache
	 * @return array{order: list<string>, prunedEvents: list<string>}
	 */
	protected function getLifecycleOrder(
		array $subscriptions,
		array $hooks,
		string $extensionsHash,
		?array $cache = null,
	): array {
		$hash = md5(serialize([
			'extensionsHash' => $extensionsHash,
			'subscriptions' => $subscriptions,
		]));

		if (
			! $this->debug
			&& $cache !== null
			&& ($cache['hash'] ?? null) === $hash
			&& is_array($cache['order'] ?? null)
			&& is_array($cache['prunedEvents'] ?? null)
		) {
			return [
				'order' => $cache['order'],
				'prunedEvents' => $cache['prunedEvents'],
			];
		}

		$result = $this->rebuildLifecycleOrder($subscriptions);

		if (! $this->debug) {
			$this->writeLifecycleCache([
				'hash' => $hash,
				'extensionsHash' => $extensionsHash,
				'hooks' => $hooks,
				'order' => $result['order'],
				'prunedEvents' => $result['prunedEvents'],
			]);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	protected function writeLifecycleCache(array $payload): void
	{
		$cacheFile = $this->paths->get('cache')->append('app_lifecycle.php')->getAbsolutePath();
		$cacheDir = dirname($cacheFile);
		if (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
			throw new Exception("Unable to create cache directory: {$cacheDir}");
		}

		$content = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
		$tempFile = tempnam($cacheDir, 'app_lifecycle_');
		if ($tempFile === false) {
			throw new Exception("Unable to create temporary lifecycle cache file in: {$cacheDir}");
		}
		if (file_put_contents($tempFile, $content, LOCK_EX) !== strlen($content)) {
			@unlink($tempFile);

			throw new Exception("Unable to write temporary lifecycle cache file: {$tempFile}");
		}
		@chmod($tempFile, 0644);
		if (! @rename($tempFile, $cacheFile)) {
			@unlink($tempFile);

			throw new Exception("Unable to move lifecycle cache file into place: {$cacheFile}");
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($cacheFile, true);
		}
	}

	/**
	 * @param array<string, list<array{event: string, optional: bool}>> $subscriptions
	 * @return array{order: list<string>, prunedEvents: list<string>}
	 */
	protected function rebuildLifecycleOrder(array $subscriptions): array
	{
		$graph = [];
		$extensionNamespaces = [];
		$prunedEvents = [];
		$eventMetadataCache = [];

		// Build a map of NamespaceRoot => ExtensionClass for legacy fallback
		foreach ($this->extensions as $class => $inst) {
			$parts = explode('\\', $class);
			array_pop($parts);
			$ns = implode('\\', $parts);
			$extensionNamespaces[$ns] = $class;
			$graph[$class] = [];
		}

		foreach ($subscriptions as $listenerClass => $entries) {
			foreach ($entries as $entry) {
				$eventClass = $entry['event'];
				$optional = $entry['optional'];

				if (! array_key_exists($eventClass, $eventMetadataCache)) {
					$eventMetadataCache[$eventClass] = InitAttributeLoader::extractEventMetadata($eventClass);
				}

				$meta = $eventMetadataCache[$eventClass];
				if ($meta !== null) {
					$ownerClass = $meta['owner'];
					if ($ownerClass !== null && ! isset($graph[$ownerClass])) {
						if ($optional) {
							$prunedEvents[$eventClass] = true;

							continue;
						}

						throw new Exception(
							"Extension {$listenerClass} listens to {$eventClass} (owned by {$ownerClass}), which is not installed."
						);
					}

					if ($meta['lazy']) {
						continue;
					}
				} else {
					$ownerClass = $this->inferEventOwner($eventClass, $extensionNamespaces);
				}

				if ($ownerClass !== null && $ownerClass !== $listenerClass) {
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

		return [
			'order' => $ordered,
			'prunedEvents' => array_keys($prunedEvents),
		];
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

		if (! is_subclass_of($extension_class, ExtensionInterface::class)) {
			throw new Exception("Extensions must implement \\ON\\Extension\\ExtensionInterface.");
		}

		$this->extensionProfiler->begin(
			$extension_class,
			'extension.install',
			'lifecycle',
			[$extension_class, '__construct']
		);

		try {
			$instance = new $extension_class($this, $extension_options);
		} finally {
			$this->extensionProfiler->end();
		}
		if (isset($instance)) {
			$this->extensions[$extension_class] = $instance;
			$this->registerExtension($instance->id(), $instance);

			return $instance;
		}

		return null;
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
	 * @template T of ExtensionInterface
	 * @return ($className is class-string<T> ? T : ExtensionInterface)
	 */
	public function getExtension(string $className): ExtensionInterface
	{
		if (! $this->hasExtension($className)) {
			throw new Exception("Extension {$className} is not installed.");
		}

		return $this->extensions[$className] ?? $this->aliases[$className];
	}

	public function setRunner(callable $runner): void
	{
		$owner = $this->init->getCurrentExtension() ?? 'unknown';
		if ($this->runner !== null) {
			throw new Exception("Conflict detected: The runner was registered by multiple extensions: [{$this->runnerOwner}, {$owner}]. Only one extension can define the runner.");
		}

		$this->runner = $runner;
		$this->runnerOwner = $owner;
	}

	public function run(): mixed
	{
		if ($this->runner === null) {
			throw new Exception('There is no extension defining the runner.');
		}

		return ($this->runner)();
	}
}
