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

	public function getUri(): string
	{
		return $this->path;
	}

	public function getFile(): FilePathInterface
	{
		return $this->file;
	}
}
