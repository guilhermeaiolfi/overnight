<?php

declare(strict_types=1);

namespace ON\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CachePathCleaner
{
	public static function clearDirectoryContents(?string $directory, bool $fast = false): void
	{
		if ($directory === null || ! is_dir($directory)) {
			return;
		}

		if ($fast) {
			self::clearDirectoryContentsFast($directory);
			return;
		}

		self::removeDirectoryContents($directory);
	}

	public static function clearDirectoryContentsFast(?string $directory): void
	{
		if ($directory === null || ! is_dir($directory)) {
			return;
		}

		$realDirectory = realpath($directory);
		if ($realDirectory === false || self::isUnsafeDirectoryTarget($realDirectory)) {
			return;
		}

		$parent = dirname($realDirectory);
		$tombstone = $parent . DIRECTORY_SEPARATOR . '.delete-' . basename($realDirectory) . '-' . bin2hex(random_bytes(8));

		if (! @rename($realDirectory, $tombstone)) {
			self::removeDirectoryContents($realDirectory);
			return;
		}

		@mkdir($realDirectory, 0777, true);

		if (! self::removeGeneratedTombstone($tombstone)) {
			self::removeDirectory($tombstone);
		}
	}

	public static function removeFile(?string $file): void
	{
		if ($file === null || $file === '' || ! is_file($file)) {
			return;
		}

		unlink($file);
	}

	private static function removeDirectoryContents(string $directory): void
	{
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
				continue;
			}

			unlink($item->getPathname());
		}
	}

	private static function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		self::removeDirectoryContents($directory);
		@rmdir($directory);
	}

	private static function removeGeneratedTombstone(string $directory): bool
	{
		$realDirectory = realpath($directory);
		if (
			$realDirectory === false
			|| ! str_starts_with(basename($realDirectory), '.delete-')
			|| self::isUnsafeDirectoryTarget($realDirectory)
		) {
			return false;
		}

		$command = DIRECTORY_SEPARATOR === '\\'
			? ['cmd.exe', '/d', '/c', 'rmdir', '/s', '/q', $realDirectory]
			: ['rm', '-rf', '--', $realDirectory];

		$process = @proc_open($command, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);

		if (! is_resource($process)) {
			return false;
		}

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		return proc_close($process) === 0;
	}

	private static function isUnsafeDirectoryTarget(string $directory): bool
	{
		$normalized = rtrim($directory, DIRECTORY_SEPARATOR);

		return $normalized === ''
			|| $normalized === DIRECTORY_SEPARATOR
			|| preg_match('/^[A-Z]:$/i', $normalized) === 1;
	}
}
