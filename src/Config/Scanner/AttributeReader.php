<?php

namespace ON\Config\Scanner;

use Adbar\Dot;
use Attribute;
use Reflection;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionAttribute;
use Reflector;

class AttributeReader 
{
    protected Dot $data;
    public function __construct(protected array $ignoreAttributes = [])
    {  
        $this->data = new Dot();
    }

    public function cacheAttribute($class, ReflectionAttribute $attr)
    {
        $this->data->set($class . ".c." . $attr->getName(), $attr->newInstance());
    }
    
    public function cacheMethodAttribute($class, string $method, ReflectionAttribute $attr): void
    {
        $this->data->set($class . ".m." . $method . "." . $attr->getName(), $attr->newInstance());
    }

    public function cacheClassConstantAttribute($class, string $classConst, ReflectionAttribute $attr)
    {
        $this->data->set($class . ".cc." . $classConst . "." . $attr->getName(), $attr->newInstance());
    }

    public function cachePropertyAttribute($class, string $property, ReflectionAttribute $attr)
    {
        $this->data->set($class . ".p." . $property . "." . $attr->getName(), $attr->newInstance());
    }

    public function getAttributes(ReflectionClass $class): array
    {
        $className = $class->getName();
        if ($this->data->has($className)) {
            return $this->data->get($className);
        }
        // Parse class attributes
        foreach ($this->getAttributesFrom($class) as $classAttribute) {
            $this->cacheAttribute($className, $classAttribute);
        }
        // Parse properties annotations
        foreach ($class->getProperties() as $property) {
            foreach ($this->getAttributesFrom($property) as $propertyAttribute) {
                $this->cachePropertyAttribute($className, $property->getName(), $propertyAttribute);
            }
        }
        // Parse methods annotations
        foreach ($class->getMethods() as $method) {
            foreach ($this->getAttributesFrom($method) as $methodAttribute) {
                $this->cacheMethodAttribute($className, $method->getName(), $methodAttribute);
            }
        }
        // Parse class constants annotations
        foreach ($class->getReflectionConstants() as $classConstant) {
            foreach ($this->getAttributesFrom($classConstant) as $constantAttribute) {
                $this->cacheClassConstantAttribute($className, $classConstant->getName(), $constantAttribute);
            }
        }
        return $this->data->get($className)?? [];
    }

    public function getAttributesFrom(Reflector $reflection, $filter = []): array
    {
        $result = [];
        if (! method_exists($reflection, 'getAttributes')) {
            return $result;
        }
        $attributes = $reflection->getAttributes();
        foreach ($attributes as $attribute) {
            if (
                in_array($attribute->getName(), $this->ignoreAttributes, true) || 
                (!empty($filter) && !in_array($attribute->getName(), $filter))
            ) {
                continue;
            }
        
            if (! class_exists($attribute->getName())) {
                $className = $methodName = $propertyName = $classConstantName = '';
                if ($reflection instanceof ReflectionClass) {
                    $className = $reflection->getName();
                } elseif ($reflection instanceof ReflectionMethod) {
                    $className = $reflection->getDeclaringClass()->getName();
                    $methodName = $reflection->getName();
                } elseif ($reflection instanceof ReflectionProperty) {
                    $className = $reflection->getDeclaringClass()->getName();
                    $propertyName = $reflection->getName();
                } elseif ($reflection instanceof ReflectionClassConstant) {
                    $className = $reflection->getDeclaringClass()->getName();
                    $classConstantName = $reflection->getName();
                }
                $message = sprintf(
                    "No attribute class found for '%s' in %s",
                    $attribute->getName(),
                    $className
                );
                if ($methodName) {
                    $message .= sprintf('->%s() method', $methodName);
                }
                if ($propertyName) {
                    $message .= sprintf('::$%s property', $propertyName);
                }
                if ($classConstantName) {
                    $message .= sprintf('::%s class constant', $classConstantName);
                }
                throw new \Exception($message);
            }
            $result[] = $attribute;
        }
        return $result;
    }
}