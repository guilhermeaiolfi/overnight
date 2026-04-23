<?php

declare(strict_types=1);

namespace ON\Container\Init;

final class ContainerInitEvents
{
	public const CONFIGURE = 'init.container.configure';
	public const READY = 'init.container.ready';
}
