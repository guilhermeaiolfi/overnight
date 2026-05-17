<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

interface LoaderInterface
{
	public function prepare(): void;

	public function load(): void;
}
