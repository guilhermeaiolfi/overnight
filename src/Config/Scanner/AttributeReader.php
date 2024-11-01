<?php

declare(strict_types=1);

namespace ON\Config\Scanner;

use Adbar\Dot;
use Attribute;
use Exception;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;

class AttributeReader
{
	protected Dot $data;

	protected array $map = [
		Attribute::TARGET_CLASS => "c",
		Attribute::TARGET_METHOD => "m",
		Attribute::TARGET_PROPERTY => "p",
		Attribute::TARGET_CLASS_CONSTANT => "cc",
	];

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

	public function load(ReflectionClass $class): void
	{
		$className = $class->getName();

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
	}

	public function getAttributes(array $classNames = [], array $attrClassNames = [], int $target = Attribute::TARGET_METHOD): array
	{
		$data = $this->data->all();
		$result = [];
		if ($target > 32) {
			return $data;
		}

		if (! isset($this->map[$target])) {
			throw new Exception("You can only filter by one target attribute");
		}
		$targetIndex = $this->map[$target];


		foreach ($this->data as $className => $places) {
			if (! empty($classNames) && ! in_array($className, $classNames)) {
				continue;
			}

			if ($target == "c") {
				foreach ($places[$target] as $attrClassName => $attrInstance) {
					if (empty($attrClassNames) || in_array($attrClassName, $attrClassNames)) {
						$result[] = [ $className, $attrInstance ];
					}
				}
			} else {
				foreach ($places[$targetIndex] as $targetName => $attributes) {
					foreach ($attributes as $attrClassName => $attrInstance) {
						if (empty($attrClassNames) || in_array($attrClassName, $attrClassNames)) {
							$result[] = new AttributeHolder($className, $targetName, $target, $attrInstance);
						}
					}
				}
			}
		}

		return $result;
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
				(! empty($filter) && ! in_array($attribute->getName(), $filter))
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

				throw new Exception($message);
			}
			$result[] = $attribute;
		}

		return $result;
	}
}
