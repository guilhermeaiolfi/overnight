<?php

declare(strict_types=1);

namespace ON;

use InvalidArgumentException;

final class Path
{
	private function __construct(
		private string $path,
		private ?string $relativeBase = null
	) {
		$this->path = self::normalize($path);
		$this->relativeBase = $relativeBase === null ? null : self::normalize($relativeBase);
	}

	public static function from(string $path, Path|string|null $base = null): self
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

	public function withRelativeBase(Path|string|null $base): self
	{
		if ($base instanceof self) {
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

	public function relativeTo(Path|string $base): string
	{
		if ($base instanceof self) {
			$base = $base->absolute();
		}

		$pathParts = self::split(self::normalize($this->path));
		$baseParts = self::split(self::normalize($base));

		if ($pathParts['prefix'] !== $baseParts['prefix']) {
			return $this->path;
		}

		if ($this->path === self::normalize($base)) {
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

	public function append(string $suffix): self
	{
		if ($suffix === '') {
			return new self($this->path, $this->relativeBase);
		}

		if (self::isAbsoluteString($suffix)) {
			throw new InvalidArgumentException('Cannot append an absolute path suffix.');
		}

		return new self($this->path . DIRECTORY_SEPARATOR . $suffix, $this->relativeBase);
	}

	public function resolveAgainst(Path|string $base): self
	{
		if ($base instanceof self) {
			$base = $base->absolute();
		}

		if ($this->isAbsolute()) {
			return $this->withRelativeBase($base);
		}

		return new self(self::normalize($base) . DIRECTORY_SEPARATOR . $this->path, $base);
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
