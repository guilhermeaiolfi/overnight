<?php

declare(strict_types=1);

namespace ON\Extension;

use ON\Application;
use ON\Init\Init;
use ON\Init\InitContext;

interface ExtensionInterface
{
	public function __construct(Application $app, array $options = []);

	public function id(): string;

	public function register(Init $init): void;

	public function getType(): int;

	public function requires(): array;

	public function getNamespace(): string;
}
