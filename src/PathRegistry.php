<?php

declare(strict_types=1);

namespace ON;

use InvalidArgumentException;

final class PathRegistry
{
	/** @var array<string, Path> */
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
			'public' => fn (): Path => $this->get('project')->append('public'),
			'config' => fn (): Path => $this->get('project')->append('config'),
			'src' => fn (): Path => $this->get('project')->append('src'),
			'var' => fn (): Path => $this->get('project')->append('var'),
			'cache' => fn (): Path => $this->get('var')->append('cache'),
			'data' => fn (): Path => $this->get('project')->append('data'),
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

	public function get(string $name): Path
	{
		$path = $this->paths[$name] ?? null;
		if ($path === null) {
			throw new InvalidArgumentException(sprintf('Unknown application path "%s".', $name));
		}

		return $path;
	}

	public function set(string $name, Path|string $path): void
	{
		$this->assertName($name);
		$path = $path instanceof Path ? $path : Path::from($path);
		$relativeBase = $name === 'project' ? $path : ($this->paths['project'] ?? null);
		if ($relativeBase !== null && ! $path->isAbsolute()) {
			$path = $relativeBase->append($path->absolute());
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
	 * @return array<string, Path>
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
		if ($path instanceof Path) {
			return;
		}

		if (! is_string($path) || trim($path) === '') {
			throw new InvalidArgumentException(sprintf('Application path "%s" must be a non-empty string.', $name));
		}
	}

	private function resolve(Path|string $path, Path $base): Path
	{
		$path = $path instanceof Path ? $path : Path::from($path);
		return $path->resolveAgainst($base);
	}
}
