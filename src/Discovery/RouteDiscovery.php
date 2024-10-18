<?php

namespace ON\Discovery;

use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Config\Scanner\TypeDefinition;
use ON\Extension\RouterExtension;
use ON\Router\Attribute\Route;
use ON\Router\RouterInterface;
use ReflectionClass;

class RouteDiscovery implements DiscoverInterface, DiscoverFileInterface, DiscoverClassInterface
{
    public AttributeReader $reader;

    protected $cachefile = "var/cache/discovery/route.cache.php";
    protected array $attributes = [];

    protected bool $changed = false;
    public function __construct(
        protected Application $app
    ) {
        $this->reader = new AttributeReader();
    }
    public function cachedTimestamp(): float
    {
        return file_exists($this->cachefile)? filemtime($this->cachefile) : 0;
    }

    public function updateFiles($files): bool
    {
        return true;
    }

    public function process(): bool
    {
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

    public function updateClasses($definitions): bool
    {
        foreach($definitions as $definition) {
            if ($definition->getType() == TypeDefinition::TYPE_CLASS) {
                if (preg_match('/(.*)Page$/', $definition->getName())) {
                    $class = new ReflectionClass($definition->getName());
                    $methods = $class->getMethods();
                    foreach ($methods as $method) {
                        foreach ($method->getAttributes() as $attr) {
                            if ($attr->getName() == Route::class) {
                                $this->attributes[$definition->getName()] = [];
                                $this->attributes[$definition->getName()][$method->getName()] = [];
                                $this->attributes[$definition->getName()][$method->getName()][] = $attr->newInstance();
                                $this->changed = true;
                            }
                        }
                    }
                }
            }
        }
        return true;
    }

    public function visitFile()
    {

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