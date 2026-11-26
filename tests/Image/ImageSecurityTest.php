<?php

declare(strict_types=1);

namespace Tests\ON\Image;

use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\FS\PathFolder;
use ON\FS\PathRegistry;
use ON\Image\Container\ImageManagerFactory;
use ON\Image\Encrypter\OpenSSL;
use ON\Image\ImageConfig;
use ON\Image\ImageManager;
use ON\Image\ImageRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;

final class ImageSecurityTest extends TestCase
{
	private string $projectDir;
	private string $outsideDir;
	private mixed $originalAppSalt = null;
	private bool $hadAppSalt = false;
	private mixed $originalServerIfNoneMatch = null;
	private bool $hadServerIfNoneMatch = false;

	protected function setUp(): void
	{
		$suffix = bin2hex(random_bytes(8));
		$this->projectDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-image-sec-proj-' . $suffix;
		$this->outsideDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-image-sec-out-' . $suffix;
		mkdir($this->projectDir . DIRECTORY_SEPARATOR . 'public', 0755, true);
		mkdir($this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads', 0755, true);
		mkdir($this->outsideDir, 0755, true);

		$this->hadAppSalt = array_key_exists('APP_SALT', $_ENV);
		$this->originalAppSalt = $_ENV['APP_SALT'] ?? null;
		unset($_ENV['APP_SALT']);

		$this->hadServerIfNoneMatch = array_key_exists('HTTP_IF_NONE_MATCH', $_SERVER);
		$this->originalServerIfNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
		unset($_SERVER['HTTP_IF_NONE_MATCH']);
	}

	protected function tearDown(): void
	{
		if ($this->hadAppSalt) {
			$_ENV['APP_SALT'] = $this->originalAppSalt;
		} else {
			unset($_ENV['APP_SALT']);
		}

		if ($this->hadServerIfNoneMatch) {
			$_SERVER['HTTP_IF_NONE_MATCH'] = $this->originalServerIfNoneMatch;
		} else {
			unset($_SERVER['HTTP_IF_NONE_MATCH']);
		}

		$this->removeDirectory($this->projectDir);
		$this->removeDirectory($this->outsideDir);
	}

	public function testImageManagerRejectsMissingSigningKey(): void
	{
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('signing key');

		new ImageManager($config, $this->createPaths());
	}

	public function testImageManagerRejectsEmptySigningKey(): void
	{
		$config = new ImageConfig([
			'key' => '   ',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('signing key');

		new ImageManager($config, $this->createPaths());
	}

	public function testImageManagerFactoryRejectsMissingKeyCleanly(): void
	{
		$config = new ImageConfig([
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);

		$container = new class ($config) implements ContainerInterface {
			public function __construct(private ImageConfig $config)
			{
			}

			public function get(string $id): mixed
			{
				if ($id === ImageConfig::class) {
					return $this->config;
				}

				if ($id === Application::class) {
					return new class () {
						public PathRegistry $paths;

						public function __construct()
						{
							$this->paths = new PathRegistry(['project' => sys_get_temp_dir(), 'public' => 'public'], sys_get_temp_dir());
						}
					};
				}

				throw new RuntimeException('Unexpected service: ' . $id);
			}

			public function has(string $id): bool
			{
				return $id === ImageConfig::class || $id === Application::class;
			}
		};

		$factory = new ImageManagerFactory($container);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('signing key');

		$factory();
	}

	public function testGetConcreteImagePathRejectsAbsolutePathsAndTraversal(): void
	{
		file_put_contents($this->projectDir . DIRECTORY_SEPARATOR . '.env', 'SECRET=1');
		$outsideFile = $this->outsideDir . DIRECTORY_SEPARATOR . 'secret.txt';
		file_put_contents($outsideFile, 'outside-secret');
		$validFile = $this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photo.jpg';
		file_put_contents($validFile, 'valid-image-data');

		$config = new ImageConfig([
			'key' => 'test-signing-key-12345',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);
		$manager = new ImageManager($config, $this->createPaths());

		// Valid relative path within root succeeds
		$resolved = $this->callGetConcreteImagePath($manager, 'photo.jpg');
		$this->assertNotNull($resolved);

		// Absolute paths (outside and inside root) MUST be rejected
		$this->assertNull($this->callGetConcreteImagePath($manager, $outsideFile));
		$this->assertNull($this->callGetConcreteImagePath($manager, $validFile));
		$this->assertNull($this->callGetConcreteImagePath($manager, '/etc/passwd'));
		$this->assertNull($this->callGetConcreteImagePath($manager, '\\secret.txt'));

		// Dot-dot traversal and bypass attempts MUST be rejected
		$this->assertNull($this->callGetConcreteImagePath($manager, '../../.env'));
		$this->assertNull($this->callGetConcreteImagePath($manager, '..\\..\\.env'));
		$this->assertNull($this->callGetConcreteImagePath($manager, '....//....//.env'));
		$this->assertNull($this->callGetConcreteImagePath($manager, 'sub/../../../.env'));

		// Null byte injection MUST be rejected
		$this->assertNull($this->callGetConcreteImagePath($manager, "photo.jpg\0.png"));
	}

	public function testUnknownTemplateReturns404InsteadOfTypeError(): void
	{
		file_put_contents(
			$this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'photo.jpg',
			'image-bytes'
		);

		$config = new ImageConfig([
			'key' => 'test-signing-key-12345',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);
		$manager = new ImageManager($config, $this->createPaths());

		$response = $manager->getCachedImageResponse(
			'tok12345',
			new ImageRequest('photo.jpg', 'nonexistent_template')
		);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testDownloadResponseQuotesAndSanitizesContentDisposition(): void
	{
		$uploadsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
		file_put_contents($uploadsDir . DIRECTORY_SEPARATOR . 'safe photo.jpg', 'image-bytes');

		$config = new ImageConfig([
			'key' => 'test-signing-key-12345',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
		]);
		$manager = new ImageManager($config, $this->createPaths());

		$response = $manager->getDownloadResponse('safe photo.jpg');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('attachment; filename="safe photo.jpg"', $response->getHeaderLine('Content-Disposition'));

		$sanitizeMethod = new ReflectionMethod($manager, 'sanitizeDownloadFilename');
		$sanitizeMethod->setAccessible(true);

		$sanitized = $sanitizeMethod->invoke($manager, "bad\"\r\nX-Injected: 1;evil.jpg");
		$this->assertSame('badX-Injected: 1evil.jpg', $sanitized);
		$this->assertStringNotContainsString("\r", $sanitized);
		$this->assertStringNotContainsString("\n", $sanitized);
		$this->assertStringNotContainsString('"', $sanitized);
	}

	public function testAcceptHeaderAllowsDownloadWildcardAndMissingAccept(): void
	{
		$uploadsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
		file_put_contents($uploadsDir . DIRECTORY_SEPARATOR . 'photo.jpg', 'image-bytes');

		$encrypter = new OpenSSL('test-signing-key-12345');
		$config = new ImageConfig([
			'key' => 'test-signing-key-12345',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
			'cache' => ['lifetime' => 60],
		]);
		$manager = new ImageManager($config, $this->createPaths(), $encrypter);
		$fallback = new FallbackRequestHandler();

		// 1. Download template with Accept: text/html (typical browser navigation)
		$downloadUri = '/' . $manager->getUri('photo.jpg', 'download');
		$downloadReq = new ServerRequest(
			uri: $downloadUri,
			method: 'GET',
			headers: ['Accept' => 'text/html,application/xhtml+xml']
		);
		$downloadRes = $manager->process($downloadReq, $fallback);
		$this->assertSame(200, $downloadRes->getStatusCode());
		$this->assertSame('attachment; filename="photo.jpg"', $downloadRes->getHeaderLine('Content-Disposition'));

		// 2. Original template with Accept: */*
		$originalUri = '/' . $manager->getUri('photo.jpg', 'original');
		$wildcardReq = new ServerRequest(
			uri: $originalUri,
			method: 'GET',
			headers: ['Accept' => '*/*']
		);
		$wildcardRes = $manager->process($wildcardReq, $fallback);
		$this->assertSame(200, $wildcardRes->getStatusCode());
		$this->assertSame('image-bytes', (string) $wildcardRes->getBody());

		// 3. Original template with missing Accept header
		$noAcceptReq = new ServerRequest(uri: $originalUri, method: 'GET');
		$noAcceptRes = $manager->process($noAcceptReq, $fallback);
		$this->assertSame(200, $noAcceptRes->getStatusCode());

		// 4. Non-download template with Accept: application/json delegates to handler
		$jsonReq = new ServerRequest(
			uri: $originalUri,
			method: 'GET',
			headers: ['Accept' => 'application/json']
		);
		$jsonRes = $manager->process($jsonReq, $fallback);
		$this->assertSame(418, $jsonRes->getStatusCode());
	}

	public function testEtagIsQuotedAndIfNoneMatchUsesPsr7RequestNotServerGlobal(): void
	{
		$uploadsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
		file_put_contents($uploadsDir . DIRECTORY_SEPARATOR . 'photo.jpg', 'etag-test-content');

		$encrypter = new OpenSSL('test-signing-key-12345');
		$config = new ImageConfig([
			'key' => 'test-signing-key-12345',
			'publicImagesDir' => 'i',
			'sourceRoots' => ['storage/uploads'],
			'cache' => ['lifetime' => 60],
		]);
		$manager = new ImageManager($config, $this->createPaths(), $encrypter);
		$fallback = new FallbackRequestHandler();

		$uri = '/' . $manager->getUri('photo.jpg', 'original');
		$expectedEtag = '"' . md5('etag-test-content') . '"';

		// Poison $_SERVER['HTTP_IF_NONE_MATCH'] — ImageManager MUST ignore it!
		$_SERVER['HTTP_IF_NONE_MATCH'] = $expectedEtag;

		$firstRes = $manager->process(new ServerRequest(uri: $uri, method: 'GET'), $fallback);
		$this->assertSame(200, $firstRes->getStatusCode());
		$this->assertSame($expectedEtag, $firstRes->getHeaderLine('ETag'));

		// Now send matching If-None-Match on the PSR-7 request
		unset($_SERVER['HTTP_IF_NONE_MATCH']);
		$cachedRes = $manager->process(
			new ServerRequest(uri: $uri, method: 'GET', headers: ['If-None-Match' => $expectedEtag]),
			$fallback
		);
		$this->assertSame(304, $cachedRes->getStatusCode());
		$this->assertSame($expectedEtag, $cachedRes->getHeaderLine('ETag'));

		// Weak ETag match in comma-separated list also returns 304
		$weakRes = $manager->process(
			new ServerRequest(uri: $uri, method: 'GET', headers: ['If-None-Match' => '"other", W/' . $expectedEtag]),
			$fallback
		);
		$this->assertSame(304, $weakRes->getStatusCode());
	}

	public function testImageConfigGetPublicImagesUriSupportsDirectoryPathInterface(): void
	{
		$config = new ImageConfig([
			'publicImagesDir' => PathFolder::from('assets/cached-images'),
		]);

		$this->assertSame('assets/cached-images', $config->getPublicImagesUri());
		$this->assertSame('assets' . DIRECTORY_SEPARATOR . 'cached-images', $config->getPublicImagesDir()->getAbsolutePath());
	}

	private function createPaths(): PathRegistry
	{
		return new PathRegistry([
			'project' => $this->projectDir,
			'public' => 'public',
		], $this->projectDir);
	}

	private function callGetConcreteImagePath(ImageManager $manager, string $filename): mixed
	{
		$method = new ReflectionMethod($manager, 'getConcreteImagePath');
		$method->setAccessible(true);

		return $method->invoke($manager, $filename);
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

final class FallbackRequestHandler implements RequestHandlerInterface
{
	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return new TextResponse('delegated', 418);
	}
}
