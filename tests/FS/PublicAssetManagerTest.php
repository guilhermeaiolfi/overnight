<?php

declare(strict_types=1);

namespace Tests\ON\FS;

use ON\FS\PathRegistry;
use ON\FS\PublicAssetManager;
use PHPUnit\Framework\TestCase;

final class PublicAssetManagerTest extends TestCase
{
	public function testUriResolvesToTheConfiguredPublicDirectoryBeforeTheFileExists(): void
	{
		$paths = new PathRegistry(['project' => sys_get_temp_dir(), 'public' => 'web-assets']);
		$assets = new PublicAssetManager($paths);

		$asset = $assets->fromUri('/images/cover.jpg');

		$this->assertSame('images/cover.jpg', $asset->getUri());
		$this->assertSame(
			$paths->get('public')->withDirectory('images')->withFile('cover.jpg')->getAbsolutePath(),
			$asset->getFile()->getAbsolutePath()
		);
	}

	public function testAnExplicitSourceFileKeepsItsOwnProjectRelativeLocation(): void
	{
		$paths = new PathRegistry(['project' => sys_get_temp_dir()]);
		$assets = new PublicAssetManager($paths);

		$asset = $assets->fromUriAndFile('/images/cover.jpg', 'var/image-cache/cover.jpg');

		$this->assertSame('images/cover.jpg', $asset->getUri());
		$this->assertSame(
			$paths->get('var')->withDirectory('image-cache')->withFile('cover.jpg')->getAbsolutePath(),
			$asset->getFile()->getAbsolutePath()
		);
	}
}
