<?php

declare(strict_types=1);

namespace ON\DataIntegration\Container;

use ON\Application;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use ON\Data\DataRuntime;
use ON\Data\Mapper\ConversionGateway;
use ON\DataIntegration\DataExtension;
use ON\DB\Cycle\CycleDatabase;
use ON\DB\DatabaseManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds the shared ON\Data Cycle query runtime from Overnight's configured
 * Cycle database and ConversionGateway.
 */
final class DataRuntimeFactory
{
	public function __invoke(ContainerInterface $container): DataRuntime
	{
		if (! $container->has(DatabaseManager::class)) {
			throw new RuntimeException('DataRuntime requires DatabaseManager.');
		}

		$app = $container->get(Application::class);
		$data = $app->hasExtension('data') ? $app->ext('data') : null;
		$databaseName = $data instanceof DataExtension
			? $data->getDatabaseName()
			: 'cycle';
		$cycleDatabaseName = $data instanceof DataExtension
			? $data->getCycleDatabaseName()
			: 'default';

		$cycle = $container->get(DatabaseManager::class)->getDatabase($databaseName);
		if (! $cycle instanceof CycleDatabase) {
			throw new RuntimeException(sprintf(
				'DataRuntime requires a CycleDatabase instance for "%s".',
				$databaseName
			));
		}

		$database = $cycle->getConnection()->database($cycleDatabaseName);
		$gateway = $container->get(ConversionGateway::class);

		return (new CycleRuntimeFactory())->create($database, $gateway);
	}
}
