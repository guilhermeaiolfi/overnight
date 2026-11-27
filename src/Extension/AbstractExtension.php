<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;
use ON\Init\Init;
use ON\Init\InitContext;

abstract class AbstractExtension implements ExtensionInterface
{
	public const ID = '';

	public function __construct(
		protected Application $app,
		protected array $options = [],
	) {
	}

	public function id(): string
	{
		return static::ID !== '' ? static::ID : static::class;
	}

	public function register(Init $init): void
	{
	}

	public function start(InitContext $context): void
	{
	}
}
