<?php

namespace ON\Discovery;

use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Extension\DiscoveryExtension;
use ON\Extension\RouterExtension;
use ON\Router\Attribute\Route;
use ON\Router\RouterInterface;
use ReflectionClass;

class RouteDiscovery implements DiscoverInterface
{
    public AttributeReader $reader;

    protected $cachefile = "var/cache/discovery/route.cache.php";
    protected array $attributes = [];
    protected ClassFinder $classFinder;
    protected bool $changed = false;

    protected float $timestamp = 0;
    public function __construct(
        protected Application $app,

    ) {
        $this->reader = new AttributeReader();
        $this->classFinder = $app->ext(DiscoveryExtension::class)->classFinder;
    }
    public function cachedTimestamp(): float
    {
        return $this->timestamp > 0? 
            $this->timestamp : 
            (file_exists($this->cachefile)? 
                filemtime($this->cachefile) : 0);
    }

    public function process(): bool
    {
        if (!$this->app->hasExtension('router')) {
            return true;
        }
        if ($this->app->isExtensionReady('router')) {
            /** @var RouterExtension $router */
            $router = $this->app->ext('router');
            foreach ($this->attributes as $className => $methods) {
                foreach ($methods as $methodName => $attributes) {
                    foreach ($attributes as $attr) {
                        /** @var Route $attr */
                        //echo $className . "::" . $methodName;exit;
                        $router->route($attr->getPath(), $className . "::" . $methodName, empty($attr->getMethods())? null : $attr->getMethods(), $attr->getName());
                    }
                }
            }
            return true;
        }
        return false;
    }

    public function updateFile($file): bool
    {
        $classes = $this->classFinder->getClassesInFile($file->getRealPath());
        foreach($classes as $className) {
            if (preg_match('/(.*)Page$/', $className)) {
                $class = new ReflectionClass($className);
                $methods = $class->getMethods();
                foreach ($methods as $method) {
                    foreach ($method->getAttributes() as $attr) {
                        if ($attr->getName() == Route::class) {
                            $this->attributes[$className] = [];
                            $this->attributes[$className][$method->getName()] = [];
                            $this->attributes[$className][$method->getName()][] = $attr->newInstance();
                            $this->changed = true;
                        }
                    }
                }
            }
        }
        return true;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function recover(): bool
    {
        $data = file_get_contents($this->cachefile);
        $this->attributes = unserialize($data);
        return true;
    }

    public function save(): bool
    {
        if ($this->changed) {
            file_put_contents($this->cachefile, serialize($this->attributes));
            return true;
        }
        return false;
    }
}