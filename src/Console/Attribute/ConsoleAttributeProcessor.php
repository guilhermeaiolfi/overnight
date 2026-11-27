<?php

declare(strict_types=1);

namespace ON\Console\Attribute;

use Attribute;
use ON\Application;
use ON\Config\Scanner\AttributeReader;
use ON\Console\Command\OvernightCommand;
use ON\Console\ConsoleExtension;
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
		if (! $this->app->hasExtension(ConsoleExtension::class)) {
			return;
		}

		$console = $this->app->ext(ConsoleExtension::class);
		$attributes = $reader->getAttributes(attrClassNames: [ ConsoleCommand::class ], target: Attribute::TARGET_METHOD);
		foreach ($attributes as $attr) {
			$command = new OvernightCommand($attr->name);
			$command->setAction($attr->__declaringClass . "::" . $attr->__methodName);
			$command->setDescription($attr->description ?? '');
			$console->addCommand($command);
		}
	}
}
