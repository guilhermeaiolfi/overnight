<?php

declare(strict_types=1);

namespace Tests\ON;

use ON\FS\Path;
use ON\FS\PathFile;
use ON\FS\PathFolder;
use ON\FS\PathRegistry;
use PHPUnit\Framework\TestCase;

final class PathRegistryTest extends TestCase
{
	public function testDerivesCanonicalPathsFromProject(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);

		$this->assertNativePath('C:/tmp/overnight-app', $paths->get('project')->getAbsolutePath());
		$this->assertNativePath('C:/tmp/overnight-app/var/cache', $paths->get('cache')->getAbsolutePath());
		$this->assertNativePath('C:/tmp/overnight-app/public', $paths->get('public')->getAbsolutePath());
	}

	public function testPathObjectsCanRenderRelativeAndAppend(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);

		$this->assertSame('.', $paths->get('project')->getRelativePath());
		$this->assertNativePath('var/cache', $paths->get('cache')->getRelativePath());
		$this->assertNativePath('var/cache/filerouting', $paths->get('cache')->append('filerouting')->getRelativePath());
	}

	public function testDirectoryPathsCreateImmutableFileAndDirectoryChildren(): void
	{
		$root = PathFolder::from('C:\\tmp\\overnight-app\\public');
		$file = $root->withDirectory('images')->withFile('cover.jpg');

		$this->assertInstanceOf(PathFile::class, $file);
		$this->assertNativePath('C:/tmp/overnight-app/public', $root->getAbsolutePath());
		$this->assertNativePath('C:/tmp/overnight-app/public/images/cover.jpg', $file->getAbsolutePath());
		$this->assertSame('cover.jpg', $file->getFilename());
		$this->assertSame('jpg', $file->getExtension());
		$this->assertNativePath('C:/tmp/overnight-app/public/images', $file->getParent()->getAbsolutePath());
	}

	public function testAllowsVarOverrideToDriveCacheDefault(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
			'var' => 'runtime',
		]);

		$this->assertNativePath('C:/tmp/overnight-app/runtime', $paths->get('var')->getAbsolutePath());
		$this->assertNativePath('C:/tmp/overnight-app/runtime/cache', $paths->get('cache')->getAbsolutePath());
	}

	public function testStandalonePathCanBeRegistered(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);
		$paths->set('uploads', Path::from('var/uploads'));

		$this->assertNativePath('C:/tmp/overnight-app/var/uploads', $paths->get('uploads')->getAbsolutePath());
	}

	private function assertNativePath(string $expected, string $actual): void
	{
		$this->assertSame(str_replace('/', DIRECTORY_SEPARATOR, $expected), $actual);
	}
}
