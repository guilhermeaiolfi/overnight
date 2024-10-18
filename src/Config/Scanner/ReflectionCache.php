<?php

namespace ON\Config\Scanner;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class ReflectionCache
{
    protected array $cached = [];

    public function reflectClass(string $className): ReflectionClass
    {
        if (! isset($this->cached['class'][$className])) {
            if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                throw new InvalidArgumentException("Class {$className} not exist");
            }
            $this->cached['class'][$className] = new ReflectionClass($className);
        }
        return $this->cached['class'][$className];
    }

    public function reflectMethod(string $className, string $method): ReflectionMethod
    {
        $key = $className . '::' . $method;
        if (! isset($this->cached['method'][$key])) {
            if (! class_exists($className) && ! trait_exists($className)) {
                throw new InvalidArgumentException("Class {$className} not exist");
            }
            $this->cached['method'][$key] = $this->reflectClass($className)->getMethod($method);
        }
        return $this->cached['method'][$key];
    }

    public function reflectProperty(string $className, string $property): ReflectionProperty
    {
        $key = $className . '::' . $property;
        if (! isset($this->cached['property'][$key])) {
            if (! class_exists($className)) {
                throw new InvalidArgumentException("Class {$className} not exist");
            }
            $this->cached['property'][$key] = $this->reflectClass($className)->getProperty($property);
        }
        return $this->cached['property'][$key];
    }

    public function clear(?string $key = null): void
    {
        if ($key === null) {
            $this->cached = [];
        }
    }

    public function getPropertyDefaultValue(ReflectionProperty $property)
    {
        return method_exists($property, 'getDefaultValue')
            ? $property->getDefaultValue()
            : $property->getDeclaringClass()->getDefaultProperties()[$property->getName()] ?? null;
    }

    public function getCached(): array
    {
        return $this->cached;
    }
}