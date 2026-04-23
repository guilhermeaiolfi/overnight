<?php

declare(strict_types=1);

namespace ON\Console\Attribute;

use Attribute;
use ON\Application;
use ON\Config\Scanner\AttributeReader;
use Psr\Container\ContainerInterface;

class ConsoleAttributeProcessor
{
	public function __construct(
		protected Application $app,
		protected ContainerInterface $container,
		protected array $options = []
	) {
	}

	public function __invoke(AttributeReader $reader): void
	{
		if (! $this->app->isCli()) {
			return;
		}

		$attributes = $reader->getAttributes(attrClassNames: [ ConsoleCommand::class ], target: Attribute::TARGET_METHOD);
		foreach ($attributes as $attr) {
			$this->app->console->addCommand(
				$attr->name,
				$attr->__declaringClass . "::" . $attr->__methodName,
				$attr->description
			);
		}
	}
}
