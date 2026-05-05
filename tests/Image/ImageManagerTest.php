<?php

declare(strict_types=1);

namespace Tests\ON\Image;

use Intervention\Image\Interfaces\ModifierInterface;
use Laminas\Diactoros\ServerRequest;
use ON\Image\Cache\ImageCacheInterface;
use ON\Image\DefaultPlaceholderImage;
use ON\Image\Encrypter\EncrypterInterface;
use ON\Image\ImageConfig;
use ON\Image\ImageManager;
use ON\FS\DirectoryPathInterface;
use ON\FS\PathFile;
use ON\FS\PathRegistry;
use ON\FS\PublicAsset;
use ON\FS\PublicAssetInterface;
use ON\Image\ImageRequest;
use ON\Image\UserFilePlaceholderImage;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class ImageManagerTest extends TestCase
{
	private string $projectDir;

	protected function setUp(): void
	{
		$this->projectDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-image-test-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir, 0777, true);
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->projectDir);
	}

	public function testGetUriReturnsCacheFilenameForExistingImage(): void
	{
		$sourceRoot = $this->createDirectory('storage/uploads');
		$this->writeFile('storage/uploads/photo.jpg', 'original-image');

		$cache = new RecordingImageCache();
		$encrypter = new StubEncrypter('signed-token');
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => [$sourceRoot],
		]);

		$manager = new ImageManager($config, $this->createPaths(), $encrypter, $cache);

		$uri = $manager->getUri('photo.jpg', 'thumb');

		$this->assertSame('i/signed-token.jpg', $uri);
		$this->assertSame('photo.jpg', $cache->lastFilenamePath);
		$this->assertSame('signed-token', $cache->lastFilenameToken);
		$this->assertSame('i/signed-token.jpg', $cache->lastPublicAsset?->getUri());
	}

	public function testGetUriReturnsSvgPlaceholderWhenRequestedImageDoesNotExist(): void
	{
		$sourceRoot = $this->createDirectory('storage/uploads');

		$cache = new RecordingImageCache();
		$encrypter = new StubEncrypter('signed-token');
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => [$sourceRoot],
		]);

		$manager = new ImageManager($config, $this->createPaths(), $encrypter, $cache);

		$uri = $manager->getUri('missing.jpg', 'thumb');

		$this->assertSame('i/signed-token.svg', $uri);
		$this->assertNull($cache->lastFilenamePath);
	}

	public function testProcessReturnsGeneratedSvgPlaceholderWhenSourceImageDoesNotExist(): void
	{
		$cache = new RecordingImageCache();
		$cache->tokenValue = 'signed-token';
		$encrypter = new StubEncrypter(
			'signed-token',
			new ImageRequest('missing.jpg', 'custom', 'cover:640,360')
		);
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
			'placeholderImageClass' => DefaultPlaceholderImage::class,
			'placeholderImageOptions' => [
				'width' => 400,
				'height' => 300,
				'showFilename' => false,
			],
			'cache' => [
				'lifetime' => 60,
			],
		]);

		$manager = new ImageManager($config, $this->createPaths(), $encrypter, $cache);
		$request = new ServerRequest(
			uri: '/i/signed-token.svg',
			method: 'GET',
			headers: ['Accept' => 'image/svg+xml']
		);

		$response = $manager->process($request, new NullRequestHandler());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
		$this->assertStringContainsString('viewBox="0 0 640 360"', (string) $response->getBody());
		$this->assertStringNotContainsString('missing.jpg', (string) $response->getBody());
		$this->assertNull($cache->lastGetToken);
	}

	public function testUserFilePlaceholderUsesConfiguredImageFile(): void
	{
		$sourceRoot = $this->createDirectory('storage/uploads');
		$this->writeFile('storage/uploads/placeholder.png', 'placeholder-image');

		$cache = new RecordingImageCache('processed-placeholder');
		$cache->tokenValue = 'signed-token';
		$encrypter = new StubEncrypter(
			'signed-token',
			new ImageRequest('missing.jpg', 'thumb')
		);
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => [$sourceRoot],
			'placeholderImageClass' => UserFilePlaceholderImage::class,
			'placeholderImageOptions' => [
				'path' => 'placeholder.png',
			],
			'templates' => [
				'thumb' => static fn ($image) => $image,
			],
			'cache' => [
				'lifetime' => 60,
			],
		]);

		$manager = new ImageManager(
			$config,
			$this->createPaths(),
			$encrypter,
			$cache,
			new UserFilePlaceholderImage($config, $cache)
		);

		$uri = $manager->getUri('missing.jpg', 'thumb');
		$this->assertSame('i/signed-token.png', $uri);
		$this->assertSame('placeholder.png', $cache->lastFilenamePath);

		$request = new ServerRequest(
			uri: '/i/signed-token.png',
			method: 'GET',
			headers: ['Accept' => 'image/png']
		);

		$response = $manager->process($request, new NullRequestHandler());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('processed-placeholder', (string) $response->getBody());
		$this->assertStringEndsWith('placeholder.png', str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $cache->lastGetPath));
	}

	public function testImageConfigNormalizesPublicImagesDirToDirectoryPath(): void
	{
		$config = new ImageConfig([
			'publicImagesDir' => 'images/cache',
		]);

		$this->assertInstanceOf(DirectoryPathInterface::class, $config->getPublicImagesDir());
		$this->assertSame('images/cache', $config->getPublicImagesUri());
	}

	public function testProcessResolvesImageFromConfiguredSourceRoots(): void
	{
		$firstRoot = $this->createDirectory('storage/uploads');
		$secondRoot = $this->createDirectory('public/images');
		$this->writeFile('public/images/nested/photo.jpg', 'resolved-image');

		$cache = new RecordingImageCache('processed-image');
		$cache->tokenValue = 'signed-token';
		$encrypter = new StubEncrypter(
			'signed-token',
			new ImageRequest('nested/photo.jpg', 'thumb')
		);
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads', 'public/images'],
			'templates' => [
				'thumb' => static fn ($image) => $image,
			],
			'cache' => [
				'lifetime' => 60,
			],
		]);

		$manager = new ImageManager($config, $this->createPaths(), $encrypter, $cache);
		$request = new ServerRequest(
			uri: '/i/signed-token.jpg',
			method: 'GET',
			headers: ['Accept' => 'image/webp']
		);

		$response = $manager->process($request, new NullRequestHandler());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('processed-image', (string) $response->getBody());
		$this->assertSame(
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $secondRoot . '/nested/photo.jpg'),
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $cache->lastGetPath)
		);
	}

	public function testSourceRootsSupportProjectAndNamedPublicPaths(): void
	{
		$this->writeFile('project-image.jpg', 'project-image');
		$this->writeFile('public/public-image.jpg', 'public-image');
		$this->writeFile('public/assets/nested-public-image.jpg', 'nested-public-image');

		$projectConfig = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['.'],
		]);
		$publicConfig = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['{public}'],
		]);
		$nestedPublicConfig = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['{public}/assets'],
		]);

		$paths = $this->createPaths();
		$projectManager = new ImageManager($projectConfig, $paths, new StubEncrypter('token'), new RecordingImageCache());
		$publicManager = new ImageManager($publicConfig, $paths, new StubEncrypter('token'), new RecordingImageCache());
		$nestedPublicManager = new ImageManager($nestedPublicConfig, $paths, new StubEncrypter('token'), new RecordingImageCache());

		$projectPath = $this->callGetImagePath($projectManager, 'project-image.jpg');
		$publicPath = $this->callGetImagePath($publicManager, 'public-image.jpg');
		$nestedPublicPath = $this->callGetImagePath($nestedPublicManager, 'nested-public-image.jpg');

		$this->assertSame(
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir . '/project-image.jpg'),
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $projectPath?->getAbsolutePath())
		);
		$this->assertSame(
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir . '/public/public-image.jpg'),
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $publicPath?->getAbsolutePath())
		);
		$this->assertSame(
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir . '/public/assets/nested-public-image.jpg'),
			str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $nestedPublicPath?->getAbsolutePath())
		);
	}

	private function createDirectory(string $relativePath): string
	{
		$directory = $this->projectDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
		if (! is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		return $directory;
	}

	private function createPaths(): PathRegistry
	{
		return new PathRegistry([
			'project' => $this->projectDir,
			'public' => 'public',
		], $this->projectDir);
	}

	private function callGetImagePath(ImageManager $manager, string $filename): mixed
	{
		$method = new \ReflectionMethod($manager, 'getConcreteImagePath');
		$method->setAccessible(true);

		return $method->invoke($manager, $filename);
	}

	private function writeFile(string $relativePath, string $contents): void
	{
		$path = $this->projectDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
		$directory = dirname($path);
		if (! is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		file_put_contents($path, $contents);
	}

	private function removeDirectory(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
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

final class RecordingImageCache implements ImageCacheInterface
{
	public mixed $lastFilenamePath = null;
	public ?string $lastFilenameToken = null;
	public ?PublicAssetInterface $lastPublicAsset = null;
	public ?string $lastGetToken = null;
	public mixed $lastGetTemplate = null;
	public mixed $lastGetPath = null;
	public string $tokenValue = '';

	public function __construct(
		private string $content = 'cached-image'
	) {
	}

	public function create(string $url, callable|ModifierInterface $template, string|\ON\FS\FilePathInterface $path): PublicAssetInterface
	{
		$this->lastGetToken = $url;
		$this->lastGetTemplate = $template;
		$this->lastGetPath = $path;
		$asset = $this->get($path, $url);
		$file = $asset->getFile();
		$directory = $file->getParent()->getAbsolutePath();
		if (! is_dir($directory)) {
			mkdir($directory, 0777, true);
		}
		file_put_contents($file->getAbsolutePath(), $this->content);

		return $asset;
	}

	public function get(string|\ON\FS\FilePathInterface $path, string $token): PublicAssetInterface
	{
		$this->lastFilenamePath = $path;
		$this->lastFilenameToken = $token;
		$extension = pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'jpg';
		$this->lastPublicAsset = new PublicAsset(
			'i/' . $token . '.' . $extension,
			PathFile::from('public/i/' . $token . '.' . $extension)
		);

		return $this->lastPublicAsset;
	}

	public function extractToken(string $path): string
	{
		return $this->tokenValue !== '' ? $this->tokenValue : $path;
	}
}

final class StubEncrypter implements EncrypterInterface
{
	public function __construct(
		private string $encryptedValue,
		private ImageRequest|false $decryptedValue = false
	) {
	}

	public function encrypt(ImageRequest $data): ?string
	{
		return $this->encryptedValue;
	}

	public function decrypt(string $data): ?ImageRequest
	{
		return $this->decryptedValue === false ? null : $this->decryptedValue;
	}
}

final class NullRequestHandler implements RequestHandlerInterface
{
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		throw new RuntimeException('Request should have been handled by ImageManager.');
	}
}
