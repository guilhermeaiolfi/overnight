<?php

declare(strict_types=1);

namespace ON\Session;

use Laminas\Session\Config\SessionConfig as LaminasSessionConfig;
use Laminas\Session\Storage\SessionArrayStorage;
use ON\Config\Config;

class SessionConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			"laminas" => [
				'save_handler' => null,
				'config' => [
					'class' => LaminasSessionConfig::class,
					'options' => [
						//'name' => 'legis',
						'gc_maxlifetime' => 3600,
					],
				],
				'storage' => SessionArrayStorage::class,
			],
		];
	}
}
