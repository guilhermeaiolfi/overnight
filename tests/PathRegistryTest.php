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

		$this->assertSame('C:\\tmp\\overnight-app', $paths->get('project')->absolute());
		$this->assertSame('C:\\tmp\\overnight-app\\var\\cache', $paths->get('cache')->absolute());
		$this->assertSame('C:\\tmp\\overnight-app\\public', $paths->get('public')->absolute());
	}

	public function testPathObjectsCanRenderRelativeAndAppend(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);

		$this->assertSame('.', $paths->get('project')->relative());
		$this->assertSame('var\\cache', $paths->get('cache')->relative());
		$this->assertSame('var\\cache\\filerouting', $paths->get('cache')->append('filerouting')->relative());
	}

	public function testDirectoryPathsCreateImmutableFileAndDirectoryChildren(): void
	{
		$root = PathFolder::from('C:\\tmp\\overnight-app\\public');
		$file = $root->withDirectory('images')->withFile('cover.jpg');

		$this->assertInstanceOf(PathFile::class, $file);
		$this->assertSame('C:\\tmp\\overnight-app\\public', $root->absolute());
		$this->assertSame('C:\\tmp\\overnight-app\\public\\images\\cover.jpg', $file->absolute());
		$this->assertSame('cover.jpg', $file->filename());
		$this->assertSame('jpg', $file->extension());
		$this->assertSame('C:\\tmp\\overnight-app\\public\\images', $file->parent()->absolute());
	}

	public function testAllowsVarOverrideToDriveCacheDefault(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
			'var' => 'runtime',
		]);

		$this->assertSame('C:\\tmp\\overnight-app\\runtime', $paths->get('var')->absolute());
		$this->assertSame('C:\\tmp\\overnight-app\\runtime\\cache', $paths->get('cache')->absolute());
	}

	public function testStandalonePathCanBeRegistered(): void
	{
		$paths = new PathRegistry([
			'project' => 'C:\\tmp\\overnight-app',
		]);
		$paths->set('uploads', Path::from('var/uploads'));

		$this->assertSame('C:\\tmp\\overnight-app\\var\\uploads', $paths->get('uploads')->absolute());
	}
}
