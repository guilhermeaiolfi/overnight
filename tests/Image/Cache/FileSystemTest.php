<?php

declare(strict_types=1);

namespace Tests\ON\Image\Cache;

use FilesystemIterator;
use InvalidArgumentException;
use ON\FS\PathFolder;
use ON\Image\Cache\FileSystem;
use ON\Image\ImageConfig;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FileSystemTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-image-cache-test-' . bin2hex(random_bytes(8));
		mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'public', 0755, true);
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->tempDir);
	}

	public function testGetAllowsOnlyAllowlistedImageExtensions(): void
	{
		$cache = new FileSystem(
			new ImageConfig(['publicImagesDir' => 'i']),
			PathFolder::from($this->tempDir . DIRECTORY_SEPARATOR . 'public')
		);

		foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'] as $ext) {
			$asset = $cache->get('photo.' . strtoupper($ext), 'abcd1234');
			$this->assertSame('i/abcd/1234.' . $ext, $asset->getUri());
		}
	}

	public function testGetNormalizesDisallowedExtensionsToJpg(): void
	{
		$cache = new FileSystem(
			new ImageConfig(['publicImagesDir' => 'i']),
			PathFolder::from($this->tempDir . DIRECTORY_SEPARATOR . 'public')
		);

		foreach (['shell.php', 'exploit.phtml', 'script.sh', 'vector.svg', 'noext'] as $filename) {
			$asset = $cache->get($filename, 'abcd1234');
			$this->assertSame('i/abcd/1234.jpg', $asset->getUri());
			$this->assertStringEndsWith('.jpg', $asset->getFile()->getAbsolutePath());
		}
	}

	public function testCreateReturnsExistingCachedFileWithoutReprocessing(): void
	{
		$publicRoot = PathFolder::from($this->tempDir . DIRECTORY_SEPARATOR . 'public');
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'driver' => 'NonExistentDriverThatWouldFailIfInvoked',
		]);
		$cache = new FileSystem($config, $publicRoot);

		$expectedAsset = $cache->get('photo.png', 'abcd1234');
		$cachedPath = $expectedAsset->getFile()->getAbsolutePath();
		mkdir(dirname($cachedPath), 0755, true);
		file_put_contents($cachedPath, 'already-cached-bytes');

		$result = $cache->create(
			'abcd1234',
			static fn ($image) => $image,
			$this->tempDir . DIRECTORY_SEPARATOR . 'source.png'
		);

		$this->assertSame($expectedAsset->getUri(), $result->getUri());
		$this->assertSame('already-cached-bytes', file_get_contents($result->getFile()->getAbsolutePath()));
	}

	public function testGetRejectsTraversalTokens(): void
	{
		$cache = new FileSystem(
			new ImageConfig(['publicImagesDir' => 'i']),
			PathFolder::from($this->tempDir . DIRECTORY_SEPARATOR . 'public')
		);

		foreach (['', '../evil', 'ab/cd', 'ab\\cd', "abcd\0ef"] as $badToken) {
			try {
				$cache->get('photo.jpg', $badToken);
				$this->fail(sprintf('Expected InvalidArgumentException for token "%s".', $badToken));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	private function removeDirectory(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());

				continue;
			}

			unlink($item->getPathname());
		}

		rmdir($dir);
	}
}
