<?php

declare(strict_types=1);

namespace Tests\ON\Cache;

use FilesystemIterator;
use ON\Cache\CachePathCleaner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CachePathCleanerTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/overnight-cleaner-test-' . bin2hex(random_bytes(8));
		mkdir($this->tempDir, 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->tempDir);
	}

	public function testFastClearHandlesShellMetacharactersInPathWithoutExecutingCommands(): void
	{
		$markerFile = $this->tempDir . '/pwned.txt';
		// Directory name containing cmd.exe metacharacters (& and %)
		$trickyDir = $this->tempDir . '/cache & echo pwned %TEMP%';
		mkdir($trickyDir . '/nested', 0755, true);
		file_put_contents($trickyDir . '/nested/item.php', '<?php return [];');

		CachePathCleaner::clearDirectoryContentsFast($trickyDir);

		$this->assertDirectoryExists($trickyDir);
		$this->assertFileDoesNotExist($trickyDir . '/nested/item.php');
		$this->assertFileDoesNotExist($markerFile);
		$this->assertSame([], glob($this->tempDir . '/.delete-*') ?: []);
	}

	public function testClearDirectoryContentsDoesNotFollowSymlinkedDirectories(): void
	{
		$cacheDir = $this->tempDir . '/cache';
		$externalDir = $this->tempDir . '/external';
		mkdir($cacheDir . '/sub', 0755, true);
		mkdir($externalDir, 0755, true);
		file_put_contents($cacheDir . '/sub/cached.txt', 'cached');
		file_put_contents($externalDir . '/keep.txt', 'must-stay');

		$linkPath = $cacheDir . '/linked-external';
		if (! @symlink($externalDir, $linkPath)) {
			$this->markTestSkipped('Creating directory symlinks requires elevated privileges on this OS.');
		}

		CachePathCleaner::clearDirectoryContents($cacheDir, false);

		$this->assertFileDoesNotExist($cacheDir . '/sub/cached.txt');
		$this->assertFalse(file_exists($linkPath) || is_link($linkPath));
		$this->assertFileExists($externalDir . '/keep.txt');
		$this->assertSame('must-stay', file_get_contents($externalDir . '/keep.txt'));
	}

	public function testRemoveFileDeletesExistingFileAndIgnoresMissing(): void
	{
		$file = $this->tempDir . '/sample.cache.php';
		file_put_contents($file, '<?php return [];');

		CachePathCleaner::removeFile($file);
		$this->assertFileDoesNotExist($file);

		// Calling again on missing file must be a no-op
		CachePathCleaner::removeFile($file);
		CachePathCleaner::removeFile(null);
		$this->assertFileDoesNotExist($file);
	}

	private function removeDirectory(string $directory): void
	{
		if (! is_dir($directory)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
			} elseif ($item->isLink() && DIRECTORY_SEPARATOR === '\\' && is_dir($item->getPathname())) {
				@rmdir($item->getPathname()) || @unlink($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($directory);
	}
}
