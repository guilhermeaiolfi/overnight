<?php

declare(strict_types=1);

namespace ON\FS;

interface PathInterface
{
	public function withRelativeBase(PathInterface|string|null $base): static;

	public function getAbsolutePath(): string;

	public function getRelativePath(): string;

	public function relativeTo(PathInterface|string $base): string;

	public function exists(): bool;

	public function isAbsolute(): bool;
}
