<?php

declare(strict_types=1);

namespace ON\FS;

interface PublicAssetInterface
{
	public function getUri(): string;

	public function getFile(): FilePathInterface;
}
