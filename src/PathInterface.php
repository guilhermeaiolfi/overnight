<?php

declare(strict_types=1);

namespace ON;

interface PathInterface
{
	public function withRelativeBase(PathInterface|string|null $base): static;

	public function absolute(): string;

	public function relative(): string;

	public function relativeTo(PathInterface|string $base): string;

	public function exists(): bool;

	public function isAbsolute(): bool;
}
