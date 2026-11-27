<?php

declare(strict_types=1);

namespace ON\Init;

use InvalidArgumentException;
use ON\Init\Attribute\InitEvent;
use ON\Init\Attribute\InitHook;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use function sprintf;

final class InitAttributeLoader
{
	/**
	 * Extracts declarative Init hook metadata from an extension class.
	 *
	 * @param class-string|object $extension
	 * @return list<array{method: string, event: string, stage: string, optional: bool}>
	 */
	public static function extractHooks(string|object $extension): array
	{
		$reflection = new ReflectionClass($extension);
		$hooks = [];

		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
				continue;
			}

			$attributes = $method->getAttributes(InitHook::class, ReflectionAttribute::IS_INSTANCEOF);
			if ($attributes === []) {
				continue;
			}

			foreach ($attributes as $attribute) {
				/** @var InitHook $hook */
				$hook = $attribute->newInstance();
				$event = $hook->event ?? self::inferEventFromMethod($method);

				if ($event === null) {
					throw new InvalidArgumentException(sprintf(
						'Method %s::%s() uses #[%s] without an explicit event name and has no class-typed first parameter.',
						$reflection->getName(),
						$method->getName(),
						$attribute->getName()
					));
				}

				$hooks[] = [
					'method' => $method->getName(),
					'event' => $event,
					'stage' => $hook->stage()->value,
					'optional' => $hook->optional,
				];
			}
		}

		return $hooks;
	}

	/**
	 * Extracts #[InitEvent] metadata from an event class, if present.
	 *
	 * @return array{owner: ?string, lazy: bool}|null
	 */
	public static function extractEventMetadata(string $eventClass): ?array
	{
		if (! class_exists($eventClass)) {
			return null;
		}

		$reflection = new ReflectionClass($eventClass);
		$attributes = $reflection->getAttributes(InitEvent::class);
		if ($attributes === []) {
			return null;
		}

		/** @var InitEvent $meta */
		$meta = $attributes[0]->newInstance();

		return [
			'owner' => $meta->owner,
			'lazy' => $meta->lazy,
		];
	}

	private static function inferEventFromMethod(ReflectionMethod $method): ?string
	{
		$parameters = $method->getParameters();
		if ($parameters === []) {
			return null;
		}

		$type = $parameters[0]->getType();
		if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
			return null;
		}

		return $type->getName();
	}
}
