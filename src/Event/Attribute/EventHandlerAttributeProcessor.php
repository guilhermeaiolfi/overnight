<?php

declare(strict_types=1);

namespace ON\Event\Attribute;

use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Event\LazyListener;

class EventHandlerAttributeProcessor
{
	public function __construct(
		protected Application $app,
		protected array $options = []
	) {

	}

	public function __invoke(AttributeReader $reader): void
	{
		$attributes = $reader->getAttributes([], [EventHandler::class]);
		foreach ($attributes as $attribute) {
			/** @var EventHandler $attr */
			$eventName = $attribute->getName();
			if (isset($attribute->__parameters[0])) {
				[$name, $type] = $attribute->__parameters[0];

				$eventName = $type;
			}

			$callable = new LazyListener(
				$this->app,
				[
					$attribute->__declaringClass,
					$attribute->__methodName,
				]
			);
			$this->app->events->registerListener($eventName, $callable, $attribute->getPriority());
		}
	}
}
