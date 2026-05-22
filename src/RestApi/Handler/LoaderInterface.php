<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

interface LoaderInterface
{
	public function prepare(): void;

	public function load(): mixed;
}
