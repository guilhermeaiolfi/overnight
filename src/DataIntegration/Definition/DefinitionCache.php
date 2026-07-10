<?php

declare(strict_types=1);

namespace ON\DataIntegration\Definition;

use RuntimeException;

final class DefinitionCache
{
	public function __construct(
		private readonly string $file,
	) {
	}

	public function getFile(): string
	{
		return $this->file;
	}

	public function exists(): bool
	{
		return is_file($this->file);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function load(): array
	{
		if (! $this->exists()) {
			throw new RuntimeException(sprintf('Definition cache file "%s" does not exist.', $this->file));
		}

		$data = require $this->file;

		if (! is_array($data)) {
			throw new RuntimeException(sprintf('Definition cache file "%s" must return an array.', $this->file));
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $definitions
	 */
	public function write(array $definitions): void
	{
		$directory = dirname($this->file);
		if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Unable to create definition cache directory "%s".', $directory));
		}

		$tempFile = $this->file . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$content = "<?php\n\nreturn " . var_export($definitions, true) . ";\n";

		if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
			throw new RuntimeException(sprintf('Unable to write temporary definition cache file "%s".', $tempFile));
		}

		if (! @rename($tempFile, $this->file)) {
			@unlink($tempFile);

			throw new RuntimeException(sprintf('Unable to move definition cache file into place at "%s".', $this->file));
		}
	}

	public function clear(): void
	{
		if ($this->exists()) {
			unlink($this->file);
		}
	}
}
