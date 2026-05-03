<?php

declare(strict_types=1);

namespace ON\FS;

interface FilePathInterface extends PathInterface
{
	public function parent(): DirectoryPathInterface;

	public function filename(): string;

	public function extension(): ?string;
}
