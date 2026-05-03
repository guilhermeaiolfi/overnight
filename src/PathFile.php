<?php

declare(strict_types=1);

namespace ON;

use InvalidArgumentException;

final class PathFile implements FilePathInterface
{
	private string $path;

	private ?string $relativeBase;

	private function __construct(string $path, ?string $relativeBase = null)
	{
		$this->path = Path::normalize($path);
		$this->relativeBase = $relativeBase === null ? null : Path::normalize($relativeBase);
	}

	public static function from(string $path, PathInterface|string|null $base = null): self
	{
		if (trim($path) === '') {
			throw new InvalidArgumentException('Path must be a non-empty string.');
		}

		$instance = new self($path);

		if ($base === null) {
			return $instance;
		}

		return $instance->resolveAgainst($base);
	}

	public function withRelativeBase(PathInterface|string|null $base): static
	{
		if ($base instanceof PathInterface) {
			$base = $base->absolute();
		}

		return new self($this->path, $base);
	}

	public function absolute(): string
	{
		return $this->path;
	}

	public function relative(): string
	{
		if ($this->relativeBase === null) {
			throw new InvalidArgumentException('No relative base is configured for this path.');
		}

		return $this->relativeTo($this->relativeBase);
	}

	public function relativeTo(PathInterface|string $base): string
	{
		return Path::relativeString($this->path, $base);
	}

	public function exists(): bool
	{
		return file_exists($this->path);
	}

	public function isAbsolute(): bool
	{
		return Path::isAbsoluteString($this->path);
	}

	public function parent(): DirectoryPathInterface
	{
		return PathFolder::from(dirname($this->path))->withRelativeBase($this->relativeBase);
	}

	public function filename(): string
	{
		return basename($this->path);
	}

	public function extension(): ?string
	{
		$extension = pathinfo($this->path, PATHINFO_EXTENSION);

		return $extension === '' ? null : $extension;
	}

	public function __toString(): string
	{
		return $this->absolute();
	}

	private function resolveAgainst(PathInterface|string $base): self
	{
		if ($base instanceof PathInterface) {
			$base = $base->absolute();
		}

		if ($this->isAbsolute()) {
			return $this->withRelativeBase($base);
		}

		return new self(Path::normalize($base) . DIRECTORY_SEPARATOR . $this->path, $base);
	}
}
