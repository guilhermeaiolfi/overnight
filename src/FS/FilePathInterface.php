<?php

declare(strict_types=1);

namespace ON\FS;

interface FilePathInterface extends PathInterface
{
	public function getParent(): DirectoryPathInterface;

	public function getFilename(): string;

	public function getExtension(): ?string;
}
