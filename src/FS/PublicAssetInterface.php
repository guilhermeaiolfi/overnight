<?php

declare(strict_types=1);

namespace ON\FS;

interface PublicAssetInterface
{
	public function path(): string;

	public function uri(): string;

	public function file(): FilePathInterface;
}
