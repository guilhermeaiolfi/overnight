<?php

declare(strict_types=1);

namespace ON\Container\Init;

final class ContainerInitEvents
{
	public const SETUP = 'init.container.setup';
	public const READY = 'init.container.ready';
}
