<?php
namespace ON;

use Exception;
use Kint\Kint;
use Psr\Container\ContainerInterface;
use ON\Extension\ExtensionInterface;
use Symfony\Component\Dotenv\Dotenv;

class Application {

    protected string $project_dir;

    protected array $extensions = [];

    protected array $installedExtensions = [];

    protected array $setupExtensions = [];

    protected array $__methods = [];

    protected array $__properties = [];

    private static ContainerInterface $container;
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
        protected ?array $options = null
    ) {
        
        $this->project_dir = $project_dir = $options["project_dir"]?? dirname(getcwd(), 1);
        
        // defines the default dir to the project root
        if ($project_dir != getcwd()) {
            chdir($project_dir);
        }

        // load .env file vars
        $dotenv = new Dotenv();
        $dotenv->load('.env');
        //d($_ENV);exit;

        $this->options["debug"] = $_ENV["APP_DEBUG"] = $options["debug"]?? "true" === $_ENV["APP_DEBUG"];

        Benchmark::start("LoadExtensions");
        $this->loadExtensions();
        Benchmark::end("LoadExtensions");
    }

    public function isDebug() {
        return $this->options["debug"];
    }

    public function ext($name) {
        return $this->getExtension($name);
    }

    public function getInstalledExtensions() {
        return $this->installedExtensions;
    }

    public function isExtensionReady($ext): bool 
    {
        return $this->extensions[$ext]->isReady();
    }

    public function getExtensionsByPendingTag(mixed $tag): array
    {
        $result = [];
        foreach($this->installedExtensions as $ext_class) {
            $tags = $this->extensions[$ext_class]->getPendingTags();
            if (in_array($tag, $tags)) {
                $result[] = $ext_class;
            }
        }
        return $result;
    }
    protected function loadExtensions() {

        Benchmark::start("LoadExtensionFile");
        $extensions = require_once ("config/extensions.php");
        Benchmark::end("LoadExtensionFile");

        $this->setupExtensions = [];
        foreach ($extensions as $extension) {
            $extension_class = $extension;
            $extension_options = [];
            if (is_array($extension)) {
                $extension_class= $extension[0];
                $extension_options= $extension[1];
            }
            Benchmark::start("install::" . $extension_class);
            $ext = $this->install($extension_class, $extension_options);
            Benchmark::end("install::" . $extension_class);
            $this->setupExtensions[] = $extension_class;
            /*Benchmark::start("setup::" . $extension_class);
            if (!$ext->setup()) {
                $this->setupExtensions[] = $extension_class;
            }
            Benchmark::end("setup::" . $extension_class);*/
        }
        Benchmark::start("Setupping");
        $counter = 0;
        $pointer = $this->setupExtensions[array_key_last($this->setupExtensions)];
        while($ext_class = array_shift($this->setupExtensions)) {
            $ext = $this->extensions[$ext_class];
            
            if ($ext) {
                //echo "setup {$ext_class}";
                $completed = $ext->setup($counter);
                if (!$completed) {
                    $this->setupExtensions[] = $ext_class;
                    if (!$completed && count($this->setupExtensions) == 1) {
                        throw new Exception("There is a deadlock booting " . $ext_class . " extension.");
                    }
                } else {
                    $ext->setReady(true);
                }
                
                if ($pointer == $ext_class) {
                    $counter++;
                    if ($completed) {
                        $pointer = count($this->setupExtensions) > 0? $this->setupExtensions[array_key_last($this->setupExtensions)] : null;
                    }
                }
            }
        }
        Benchmark::end("Setupping");

        Benchmark::start("Readying");
        foreach($this->installedExtensions as $extension) {
            $ext = $this->extensions[$extension];
            
            if (isset($ext)) {
                $ext->ready();
            }
        }
        Benchmark::end("Readying");
    }

    public function install(string $extension_class, array $extension_options): mixed {


        if (!class_exists($extension_class)) {
            throw new Exception("It was passed an invalid class as extension.");
        }
        $interfaces = class_implements($extension_class);

        if (isset($interfaces['ON\Extension\ExtesionInterface'])) {
            throw new Exception("Extensions must implement \ON\Extension\ExtesionInterface.");
        }

        $this->extensions[$extension_class] = $extension_class::install($this, $extension_options);
        $this->installedExtensions[] = $extension_class;
        return $this->extensions[$extension_class];
    }

    public function registerExtension($name, $obj): void {
        $this->extensions[$name] = $obj;
    }

    public function hasExtension($class): bool {
        return array_key_exists($class, $this->extensions);
    }

    public function getExtension(string $class): ExtensionInterface {
        if (!$this->hasExtension($class)) {
            throw new Exception("Extension {$class} is not installed.");
        }
        return $this->extensions[$class];
    }

    /**
     * Returns currently active container scope if any.
     *
     * @return null|ContainerInterface
     */
    public static function getContainer(): ?ContainerInterface
    {
        return self::$container?? null;
    }

    public static function setContainer($container) {
        self::$container = $container;
    }
    
    public function __get($name)
    {
        return $this->__properties[$name];
    }

    public function __set($name, $value)
    {
        $this->__properties[$name] = $value;
    }

    public function __call ($name, $args)    {
        return call_user_func_array ($this->__methods[$name], $args); 
    }

    public function registerMethod($name, callable $method) {
        $this->__methods[$name] = $method;
    }
}