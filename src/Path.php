<?php

declare(strict_types=1);

namespace ON;

use InvalidArgumentException;

class Path implements DirectoryPathInterface
{
	private string $path;

	private ?string $relativeBase;

	protected function __construct(
		string $path,
		?string $relativeBase = null
	) {
		$this->path = self::normalize($path);
		$this->relativeBase = $relativeBase === null ? null : self::normalize($relativeBase);
	}

	public static function from(string $path, PathInterface|string|null $base = null): static
	{
		if (trim($path) === '') {
			throw new InvalidArgumentException('Path must be a non-empty string.');
		}

		$instance = new static($path);

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

		return new static($this->path, $base);
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
		return self::relativeString($this->path, $base);
	}

	public function append(string $suffix): static
	{
		if ($suffix === '') {
			return new static($this->path, $this->relativeBase);
		}

		if (self::isAbsoluteString($suffix)) {
			throw new InvalidArgumentException('Cannot append an absolute path suffix.');
		}

		return new static($this->path . DIRECTORY_SEPARATOR . $suffix, $this->relativeBase);
	}

	public function parent(): DirectoryPathInterface
	{
		return new static(dirname($this->path), $this->relativeBase);
	}

	public function withFile(string $name): FilePathInterface
	{
		return PathFile::from($this->path . DIRECTORY_SEPARATOR . $name)->withRelativeBase($this->relativeBase);
	}

	public function withDirectory(string $name): static
	{
		return $this->append($name);
	}

	public function resolveAgainst(PathInterface|string $base): static
	{
		if ($base instanceof PathInterface) {
			$base = $base->absolute();
		}

		if ($this->isAbsolute()) {
			return $this->withRelativeBase($base);
		}

		return new static(self::normalize($base) . DIRECTORY_SEPARATOR . $this->path, $base);
	}

	public function exists(): bool
	{
		return file_exists($this->path);
	}

	public function isAbsolute(): bool
	{
		return self::isAbsoluteString($this->path);
	}

	public function __toString(): string
	{
		return $this->absolute();
	}

	public static function relativeString(string $path, PathInterface|string $base): string
	{
		if ($base instanceof PathInterface) {
			$base = $base->absolute();
		}

		$pathParts = self::split(self::normalize($path));
		$baseParts = self::split(self::normalize($base));

		if ($pathParts['prefix'] !== $baseParts['prefix']) {
			return self::normalize($path);
		}

		if (self::normalize($path) === self::normalize($base)) {
			return '.';
		}

		$common = 0;
		$max = min(count($pathParts['segments']), count($baseParts['segments']));
		while ($common < $max && $pathParts['segments'][$common] === $baseParts['segments'][$common]) {
			++$common;
		}

		$relative = array_fill(0, count($baseParts['segments']) - $common, '..');
		$relative = array_merge($relative, array_slice($pathParts['segments'], $common));

		return $relative === [] ? '.' : implode(DIRECTORY_SEPARATOR, $relative);
	}

	public static function isAbsoluteString(string $path): bool
	{
		return preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|\/)/', $path) === 1;
	}

	public static function normalize(string $path): string
	{
		$path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
		$prefix = '';

		if (preg_match('/^[A-Za-z]:/', $path) === 1) {
			$prefix = substr($path, 0, 2);
			$path = substr($path, 2);
		} elseif (str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
			$prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
			$path = substr($path, 2);
		} elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
			$prefix = DIRECTORY_SEPARATOR;
			$path = substr($path, 1);
		}

		$segments = preg_split('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', $path) ?: [];
		$normalized = [];
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				if ($normalized !== [] && end($normalized) !== '..') {
					array_pop($normalized);
					continue;
				}

				if ($prefix === '') {
					$normalized[] = $segment;
				}

				continue;
			}

			$normalized[] = $segment;
		}

		$normalizedPath = implode(DIRECTORY_SEPARATOR, $normalized);
		if ($prefix === DIRECTORY_SEPARATOR) {
			return DIRECTORY_SEPARATOR . $normalizedPath;
		}

		if ($prefix === DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR) {
			return DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . $normalizedPath;
		}

		if ($prefix !== '') {
			return $prefix . DIRECTORY_SEPARATOR . $normalizedPath;
		}

		return $normalizedPath;
	}

	/**
	 * @return array{prefix:string,segments:array<int,string>}
	 */
	private static function split(string $path): array
	{
		$prefix = '';

		if (preg_match('/^[A-Za-z]:/', $path) === 1) {
			$prefix = substr($path, 0, 2);
			$path = substr($path, 2);
		} elseif (str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
			$prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
			$path = substr($path, 2);
		} elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
			$prefix = DIRECTORY_SEPARATOR;
			$path = substr($path, 1);
		}

		$path = trim($path, DIRECTORY_SEPARATOR);
		$segments = $path === ''
			? []
			: (preg_split('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', $path) ?: []);

		return [
			'prefix' => $prefix,
			'segments' => $segments,
		];
	}
}
