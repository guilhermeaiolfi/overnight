<?php

declare(strict_types=1);

namespace ON\Console\Attribute;

use Attribute;
use Exception;
use ON\Config\Scanner\ReflectionAnalyzableInterface;
use ReflectionMethod;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ConsoleCommand implements ReflectionAnalyzableInterface
{
	public string $__declaringClass = "";
	public string $__methodName = "";

	/**
	 * @param string      $name        The name of the command, used when calling it (i.e. "cache:clear")
	 * @param string|null $description The description of the command, displayed with the help page
	 * @param string[]    $aliases     The list of aliases of the command. The command will be executed when using one of them (i.e. "cache:clean")
	 * @param bool        $hidden      If true, the command won't be shown when listing all the available commands, but it can still be run as any other command
	 */
	public function __construct(
		public string $name,
		public ?string $description = null,
		array $aliases = [],
		bool $hidden = false,
	) {
		if (! $hidden && ! $aliases) {
			return;
		}

		$name = explode('|', $name);
		$name = array_merge($name, $aliases);

		if ($hidden && '' !== $name[0]) {
			array_unshift($name, '');
		}

		$this->name = implode('|', $name);
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
	}
}
