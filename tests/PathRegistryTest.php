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

		$this->assertSame('C:\\tmp\\overnight-app', $paths->get('project')->getAbsolutePath());
		$this->assertSame('C:\\tmp\\overnight-app\\var\\cache', $paths->get('cache')->getAbsolutePath());
		$this->assertSame('C:\\tmp\\overnight-app\\public', $paths->get('public')->getAbsolutePath());
	}

	public function testPathObjectsCanRenderRelativeAndAppend(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);

		$this->assertSame('.', $paths->get('project')->getRelativePath());
		$this->assertSame('var\\cache', $paths->get('cache')->getRelativePath());
		$this->assertSame('var\\cache\\filerouting', $paths->get('cache')->append('filerouting')->getRelativePath());
	}

	public function testDirectoryPathsCreateImmutableFileAndDirectoryChildren(): void
	{
		$root = PathFolder::from('C:\\tmp\\overnight-app\\public');
		$file = $root->withDirectory('images')->withFile('cover.jpg');

		$this->assertInstanceOf(PathFile::class, $file);
		$this->assertSame('C:\\tmp\\overnight-app\\public', $root->getAbsolutePath());
		$this->assertSame('C:\\tmp\\overnight-app\\public\\images\\cover.jpg', $file->getAbsolutePath());
		$this->assertSame('cover.jpg', $file->getFilename());
		$this->assertSame('jpg', $file->getExtension());
		$this->assertSame('C:\\tmp\\overnight-app\\public\\images', $file->getParent()->getAbsolutePath());
	}

	public function testAllowsVarOverrideToDriveCacheDefault(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
			'var' => 'runtime',
		]);

		$this->assertSame('C:\\tmp\\overnight-app\\runtime', $paths->get('var')->getAbsolutePath());
		$this->assertSame('C:\\tmp\\overnight-app\\runtime\\cache', $paths->get('cache')->getAbsolutePath());
	}

	public function testStandalonePathCanBeRegistered(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);
		$paths->set('uploads', Path::from('var/uploads'));

		$this->assertSame('C:\\tmp\\overnight-app\\var\\uploads', $paths->get('uploads')->getAbsolutePath());
	}
}
