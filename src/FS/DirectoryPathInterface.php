<?php

declare(strict_types=1);

namespace ON\FS;

interface DirectoryPathInterface extends PathInterface
{
	public function parent(): self;

	public function append(string $suffix): static;

	public function withFile(string $name): FilePathInterface;

	public function withDirectory(string $name): static;
}
