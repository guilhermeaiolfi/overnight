<?php

declare(strict_types=1);

namespace ON\Event\Attribute;

use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Event\EventsExtension;
use ON\Event\LazyListener;
use Psr\Container\ContainerInterface;

class EventHandlerAttributeProcessor
{
	public function __construct(
		protected Application $app,
		protected ContainerInterface $container,
		protected array $options = []
	) {

	}

	public function __invoke(AttributeReader $reader): void
	{
		$attributes = $reader->getAttributes([], [EventHandler::class]);
		foreach ($attributes as $attribute) {
			/** @var EventHandler $attribute */
			$eventName = $attribute->getName();
			if (($eventName === null || $eventName === '') && isset($attribute->__parameters[0])) {
				[$name, $type] = $attribute->__parameters[0];

				if (is_string($type) && $type !== '') {
					$eventName = $type;
				}
			}

			if ($eventName === null || $eventName === '') {
				continue;
			}

			$callable = new LazyListener(
				$this->container,
				[
					$attribute->__declaringClass,
					$attribute->__methodName,
				]
			);
			$this->app->ext(EventsExtension::class)->registerListener(
				$eventName,
				$callable,
				$attribute->getPriority(),
				$attribute->getOnce()
			);
		}
	}
}
