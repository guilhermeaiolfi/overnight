<?php

declare(strict_types=1);

namespace ON\FS;

final class PublicAsset implements PublicAssetInterface
{
	public function __construct(
		private string $path,
		private FilePathInterface $file
	) {
	}

	public function path(): string
	{
		return $this->path;
	}

	public function uri(): string
	{
		return $this->path;
	}

	public function file(): FilePathInterface
	{
		return $this->file;
	}
}
