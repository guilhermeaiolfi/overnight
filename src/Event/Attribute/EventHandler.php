<?php

declare(strict_types=1);

namespace ON\Event\Attribute;

use Attribute;
use Exception;
use League\Event\ListenerPriority;
use ON\Config\Scanner\ReflectionAnalyzableInterface;
use ReflectionMethod;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class EventHandler implements ReflectionAnalyzableInterface
{
	public string $__declaringClass = "";
	public string $__methodName = "";
	public array $__parameters = [];

	/**
	 * @param string|null                      $name         The route name (i.e. "app_user_login")
	 */
	public function __construct(
		string|array|null $path = null,
		private ?string $name = null,
		private ?string $eventName = null,
		private ?bool $once = false,
		private ?int $priority = ListenerPriority::NORMAL,
	) {

	}

	public function getOnce(): bool
	{
		return $this->once;
	}

	public function getPriority(): int
	{
		return $this->priority;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function __analyzeReflection(array $reflectors): void
	{
		$count = count($reflectors);
		if ($count < 3) {
			throw new Exception("Is it possible yet to use Route attributes in a class?");
		}

		$methodReflector = $reflectors[$count - 2];

		if (! ($methodReflector instanceof ReflectionMethod)) {
			throw new Exception("It is only possible to use the Route attribute in methods.");
		}
		/** @var ReflectionMethod $reflector */
		$this->__declaringClass = $methodReflector->getDeclaringClass()->getName();
		$this->__methodName = $methodReflector->getName();

		$parameters = $methodReflector->getParameters();
		$params = [];
		foreach ($parameters as $parameter) {
			$params[] = [$parameter->getName(), $parameter->getType()->getName()];
		}

		$this->__parameters = $params;
	}
}
