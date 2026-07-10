<?php

declare(strict_types=1);

namespace ON\DataIntegration\Mapper;

use ON\Application;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapping;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ConversionGatewayFactory
{
	public function __invoke(ContainerInterface $container): ConversionGateway
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->setConstructor(
			static function (string $component) use ($container): object {
				try {
					$instance = $container->get($component);

					if (! $instance instanceof $component) {
						throw new RuntimeException(sprintf(
							'Container returned an invalid mapper component for "%s".',
							$component
						));
					}

					return $instance;
				} catch (NotFoundExceptionInterface) {
				}

				return new $component();
			}
		);

		$config = $this->config($container);
		// MapperManager::prepend() unshifts, so apply declared prepends in reverse
		// to preserve declaration order as runtime precedence (first prepended = first tried).
		foreach (array_reverse($config->prependedComponents) as $component) {
			$gateway->getMapperManager()->prepend($component);
		}

		foreach ($config->components as $component) {
			$gateway->getMapperManager()->register($component);
		}

		Mapping::setDefaultGateway($gateway);

		return $gateway;
	}

	private function config(ContainerInterface $container): DataMapperConfig
	{
		$app = $container->get(Application::class);
		if ($app->hasExtension('config')) {
			return $app->config->get(DataMapperConfig::class);
		}

		return new DataMapperConfig();
	}
}
