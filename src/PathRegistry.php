<?php

declare(strict_types=1);

namespace ON;

use InvalidArgumentException;

final class PathRegistry
{
	/** @var array<string, DirectoryPathInterface> */
	private array $paths = [];

	/**
	 * @param array<string, string|Path> $paths
	 */
	public function __construct(array $paths, ?string $baseDir = null)
	{
		$baseDir ??= getcwd();
		$base = Path::from($baseDir);

		foreach ($paths as $name => $path) {
			$this->assertName($name);
			$this->assertValue($name, $path);
		}

		$project = $paths['project'] ?? $base;
		$this->set('project', $this->resolve($project, $base));

		$canonicalDefaults = [
			'public' => fn (): DirectoryPathInterface => $this->get('project')->append('public'),
			'config' => fn (): DirectoryPathInterface => $this->get('project')->append('config'),
			'src' => fn (): DirectoryPathInterface => $this->get('project')->append('src'),
			'var' => fn (): DirectoryPathInterface => $this->get('project')->append('var'),
			'cache' => fn (): DirectoryPathInterface => $this->get('var')->append('cache'),
			'data' => fn (): DirectoryPathInterface => $this->get('project')->append('data'),
		];

		foreach ($canonicalDefaults as $name => $default) {
			if (array_key_exists($name, $paths)) {
				$this->set($name, $this->resolve($paths[$name], $this->get('project')));
				continue;
			}

			$this->set($name, $default());
		}

		foreach ($paths as $name => $path) {
			if ($name === 'project' || isset($canonicalDefaults[$name])) {
				continue;
			}

			$this->set($name, $this->resolve($path, $this->get('project')));
		}
	}

	public function get(string $name): DirectoryPathInterface
	{
		$path = $this->paths[$name] ?? null;
		if ($path === null) {
			throw new InvalidArgumentException(sprintf('Unknown application path "%s".', $name));
		}

		return $path;
	}

	public function set(string $name, PathInterface|string $path): void
	{
		$this->assertName($name);
		$path = $path instanceof DirectoryPathInterface ? $path : Path::from((string) $path);
		$relativeBase = $name === 'project' ? $path : ($this->paths['project'] ?? null);
		if ($relativeBase !== null && ! $path->isAbsolute()) {
			$path = Path::from($path->absolute(), $relativeBase);
		}

		$this->paths[$name] = $relativeBase === null ? $path : $path->withRelativeBase($relativeBase);
	}

	public function has(string $name): bool
	{
		return isset($this->paths[$name]);
	}

	public function exists(string $name): bool
	{
		return $this->get($name)->exists();
	}

	/**
	 * @return array<string, DirectoryPathInterface>
	 */
	public function all(): array
	{
		return $this->paths;
	}

	private function assertName(mixed $name): void
	{
		if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
			throw new InvalidArgumentException(sprintf('Invalid application path name "%s".', (string) $name));
		}
	}

	private function assertValue(string $name, mixed $path): void
	{
		if ($path instanceof PathInterface) {
			return;
		}

		if (! is_string($path) || trim($path) === '') {
			throw new InvalidArgumentException(sprintf('Application path "%s" must be a non-empty string.', $name));
		}
	}

	private function resolve(PathInterface|string $path, DirectoryPathInterface $base): DirectoryPathInterface
	{
		$path = $path instanceof DirectoryPathInterface ? $path : Path::from((string) $path);
		return $path->resolveAgainst($base);
	}
}
