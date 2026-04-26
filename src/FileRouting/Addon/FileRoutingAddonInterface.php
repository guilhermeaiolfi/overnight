<?php

declare(strict_types=1);

namespace ON\FileRouting\Addon;

interface FileRoutingAddonInterface
{
	public function process(array $pageContext, array $data): array;
}
