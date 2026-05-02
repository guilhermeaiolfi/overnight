<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;
use ON\Init\Init;
use ON\Init\InitContext;

abstract class AbstractExtension implements ExtensionInterface
{
	public const TYPE_MODULE = 1;
	public const TYPE_EXTENSION = 2;
	public const TYPE_AGGREGATION = 3;

	public const VERSION = "UNVERSIONED";

	public const NAMESPACE = "core.extensions.abstract";

	public const ID = "";

	protected int $type = self::TYPE_MODULE;

	public function __construct(Application $app, array $options = [])
	{
	}

	public function getType(): int
	{
		return $this->type;
	}

	public function id(): string
	{
		return static::ID !== "" ? static::ID : static::class;
	}

	public function register(Init $init): void
	{
	}

	public function start(InitContext $context): void
	{
	}


	public function getNamespace(): string
	{
		return static::NAMESPACE;
	}
}
