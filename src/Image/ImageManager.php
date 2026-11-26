<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Interfaces\ModifierInterface;
use InvalidArgumentException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\StreamFactory;
use ON\FS\DirectoryPathInterface;
use ON\FS\FilePathInterface;
use ON\FS\Path;
use ON\FS\PathFile;
use ON\FS\PathRegistry;
use ON\Image\Cache\FileSystem;
use ON\Image\Cache\ImageCacheInterface;
use ON\Image\Encrypter\EncrypterInterface;
use ON\Image\Encrypter\OpenSSL;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class ImageManager implements MiddlewareInterface
{
	public const MAX_FILENAME_LENGTH = 200;

	private ?string $signatureKey = null;

	private ?ServerRequestInterface $currentRequest = null;

	/**
	 * @var list<DirectoryPathInterface>|null
	 */
	private ?array $resolvedSourceRoots = null;

	/**
	 * Set the signature key used to encode/decode the data.
	 */
	public function __construct(
		protected ImageConfig $imageCfg,
		protected PathRegistry $paths,
		protected ?EncrypterInterface $encrypter = null,
		protected ?ImageCacheInterface $imageCache = null,
		protected ?PlaceholderImageInterface $placeholderImage = null
	) {
		$configuredKey = $imageCfg->get('key');
		if (! is_string($configuredKey) || trim($configuredKey) === '') {
			$configuredKey = isset($_ENV['APP_SALT']) && is_string($_ENV['APP_SALT']) ? $_ENV['APP_SALT'] : '';
		}
		$this->signatureKey = trim($configuredKey) !== '' ? $configuredKey : null;

		if (! isset($encrypter)) {
			if ($this->signatureKey === null) {
				throw new RuntimeException(
					'No image signing key configured. Set "key" in ImageConfig or APP_SALT in the environment.'
				);
			}

			$this->encrypter = new OpenSSL($this->signatureKey);
		}

		if (! isset($imageCache)) {
			$this->imageCache = new FileSystem($imageCfg, $this->paths->get('public'));
		}

		$this->placeholderImage ??= new DefaultPlaceholderImage($imageCfg, $this->imageCache);
	}

	public function getCurrentRequest(): ?ServerRequestInterface
	{
		return $this->currentRequest;
	}

	public function getUri(string $sourceFilePath, string $template, mixed $options = null): string
	{
		$imageRequest = new ImageRequest($sourceFilePath, $template, $options);
		$token = $this->encrypter->encrypt($imageRequest);
		if ($token === null || $token === '') {
			throw new RuntimeException('Failed to encrypt image request.');
		}

		if (! $this->imageExists($sourceFilePath)) {
			return $this->placeholderImage->getUri($this, $token, $imageRequest);
		}

		return $this->imageCache->get($sourceFilePath, $token)->getUri();
	}

	protected function imageExists(string $filename): bool
	{
		return $this->getConcreteImagePath($filename) !== null;
	}

	/**
	 * Process a request and return a response.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$imageBasePath = '/' . trim($this->imageCfg->getPublicImagesUri(), '/') . '/';
		$uri = $request->getUri();
		$path = $uri->getPath();

		// if the path is not in the images folder, ignore, we shouldn't handle
		if (! str_contains($path, $imageBasePath)) {
			return $handler->handle($request);
		}

		[, $token] = explode($imageBasePath, $path, 2);

		if ($extensionPos = strrpos($token, '.')) {
			$token = substr($token, 0, $extensionPos);
		}

		$token = $this->imageCache->extractToken($token);

		$imageRequest = $this->encrypter->decrypt($token);

		if (! $imageRequest) {
			return $handler->handle($request);
		}

		if (! $this->isAcceptableRequest($request, $imageRequest)) {
			return $handler->handle($request);
		}

		$previousRequest = $this->currentRequest;
		$this->currentRequest = $request;

		try {
			return $this->getResponse($token, $imageRequest, $request);
		} finally {
			$this->currentRequest = $previousRequest;
		}
	}

	/**
	 * Get HTTP response of either original image file or
	 * template applied file.
	 */
	public function getResponse(
		string $token,
		ImageRequest $imageRequest,
		?ServerRequestInterface $request = null
	): ResponseInterface {
		if ($this->getConcreteImagePath($imageRequest->getSourceFilePath()) === null) {
			return $this->placeholderImage->getResponse($this, $token, $imageRequest);
		}

		switch (strtolower($imageRequest->getTemplate())) {
			case 'original':
				return $this->getOriginalImageResponse($imageRequest->getSourceFilePath(), $request);

			case 'download':
				return $this->getDownloadResponse($imageRequest->getSourceFilePath(), $request);

			default:
				return $this->getCachedImageResponse($token, $imageRequest, $request);
		}
	}

	/**
	 * Get HTTP response of template applied image file
	 */
	public function getCachedImageResponse(
		string $token,
		ImageRequest $imageRequest,
		?ServerRequestInterface $request = null
	): ResponseInterface {
		$template = $this->getTemplate($imageRequest->getTemplate(), $imageRequest->getOptions());
		$sourceFile = $this->getConcreteImagePath($imageRequest->getSourceFilePath());

		if (! $sourceFile) {
			return new EmptyResponse(404);
		}
		if ($template instanceof ResponseInterface) {
			return $template;
		}

		$asset = $this->imageCache->create($token, $template, $sourceFile);
		$content = (string) file_get_contents($asset->getFile()->getAbsolutePath());

		return $this->buildResponse($content, null, $request);
	}

	/**
	 * Get HTTP response of original image file
	 */
	public function getOriginalImageResponse(
		string $filename,
		?ServerRequestInterface $request = null
	): ResponseInterface {
		$sourceFile = $this->getConcreteImagePath($filename);
		if ($sourceFile === null) {
			return new EmptyResponse(404);
		}

		return $this->buildResponse((string) file_get_contents($sourceFile->getAbsolutePath()), null, $request);
	}

	/**
	 * Get HTTP response of original image as download
	 */
	public function getDownloadResponse(
		string $filename,
		?ServerRequestInterface $request = null
	): ResponseInterface {
		$response = $this->getOriginalImageResponse($filename, $request);
		if ($response->getStatusCode() === 404) {
			return $response;
		}

		$safeFilename = $this->sanitizeDownloadFilename($filename);

		return $response->withHeader(
			'Content-Disposition',
			'attachment; filename="' . $safeFilename . '"'
		);
	}

	/**
	 * Returns corresponding template object from given template name
	 */
	protected function getTemplate(string $template, mixed $options = null): ResponseInterface|callable|ModifierInterface
	{
		$templates = $this->imageCfg->get('templates', []);
		$templateConfig = is_array($templates) ? ($templates[$template] ?? null) : null;

		switch (true) {
			// closure template found
			case is_callable($templateConfig):
				return $templateConfig;

				// filter template found
			case is_string($templateConfig) && class_exists($templateConfig):
				if ($templateConfig === CustomTemplate::class) {
					$maxWidth = (int) $this->imageCfg->get('maxWidth', CustomTemplate::DEFAULT_MAX_DIMENSION);
					$maxHeight = (int) $this->imageCfg->get('maxHeight', CustomTemplate::DEFAULT_MAX_DIMENSION);

					return new CustomTemplate(is_string($options) ? $options : null, $maxWidth, $maxHeight);
				}

				if (isset($options)) {
					return new $templateConfig($options);
				}

				return new $templateConfig(null);

			default:
				// template not found
				return new EmptyResponse(404);
		}
	}

	/**
	 * Returns full image path from given filename strictly within configured source roots.
	 */
	protected function getConcreteImagePath(string $filename): ?FilePathInterface
	{
		$filename = str_replace('\\', '/', $filename);
		if (! $this->isRelativeSourcePath($filename)) {
			return null;
		}

		$filename = str_replace(DIRECTORY_SEPARATOR, '/', Path::normalize($filename));
		[$roots, $relativeFilename] = $this->getSourceLocation($filename);
		if (! $this->isRelativeSourcePath($relativeFilename)) {
			return null;
		}

		foreach ($roots as $root) {
			$realRoot = realpath($root->getAbsolutePath());
			if ($realRoot === false || ! is_dir($realRoot)) {
				continue;
			}

			$realRootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

			try {
				$imagePath = PathFile::from($relativeFilename, $root);
			} catch (InvalidArgumentException) {
				continue;
			}

			$realCandidate = realpath($imagePath->getAbsolutePath());
			if ($realCandidate === false || ! is_file($realCandidate)) {
				continue;
			}

			if (! $this->isPathWithinRoot($realCandidate, $realRootPrefix)) {
				continue;
			}

			return $imagePath;
		}

		return null;
	}

	private function isRelativeSourcePath(string $filename): bool
	{
		return $filename !== ''
			&& ! str_contains($filename, "\0")
			&& ! str_starts_with($filename, '/')
			&& preg_match('/^[A-Za-z]:/', $filename) !== 1
			&& ! in_array('..', explode('/', $filename), true);
	}

	/**
	 * @return array{list<DirectoryPathInterface>, string}
	 */
	private function getSourceLocation(string $filename): array
	{
		$aliases = $this->imageCfg->get('sourceAliases', []);
		if (is_array($aliases)) {
			foreach ($aliases as $alias => $directory) {
				if (! is_string($alias) || $alias === '' || ! is_string($directory) || trim($directory) === '') {
					continue;
				}

				$prefix = $alias . '/';
				if (str_starts_with($filename, $prefix)) {
					$directory = $this->resolveSourceRoot($directory, $this->paths->get('project'));

					return [[$directory], substr($filename, strlen($prefix))];
				}
			}
		}

		return [$this->getSourceRoots(), $filename];
	}

	/**
	 * @return list<DirectoryPathInterface>
	 */
	protected function getSourceRoots(): array
	{
		if ($this->resolvedSourceRoots !== null) {
			return $this->resolvedSourceRoots;
		}

		$configuredRoots = $this->imageCfg->get('sourceRoots', []);
		if (! is_array($configuredRoots)) {
			$this->resolvedSourceRoots = [];

			return $this->resolvedSourceRoots;
		}

		$projectDir = $this->paths->get('project');
		$this->resolvedSourceRoots = [];

		foreach ($configuredRoots as $root) {
			if (! is_string($root) || trim($root) === '') {
				continue;
			}

			$this->resolvedSourceRoots[] = $this->resolveSourceRoot($root, $projectDir);
		}

		return $this->resolvedSourceRoots;
	}

	protected function resolveSourceRoot(string $root, DirectoryPathInterface $projectDir): DirectoryPathInterface
	{
		$trimmedRoot = trim($root);
		$normalizedRoot = str_replace('\\', '/', $trimmedRoot);
		$registryKey = rtrim($normalizedRoot, '/');

		if ($registryKey === '.') {
			return $projectDir;
		}

		if (preg_match('/^\{([a-z][a-z0-9_]*)\}(?:\/(.*))?$/', $normalizedRoot, $matches) === 1) {
			$pathName = $matches[1];
			$suffix = $matches[2] ?? '';

			if ($this->paths->has($pathName)) {
				$basePath = $this->paths->get($pathName);

				return $suffix === '' ? $basePath : Path::from($suffix, $basePath);
			}
		}

		if ($registryKey !== '' && $this->paths->has($registryKey)) {
			return $this->paths->get($registryKey);
		}

		return Path::from($trimmedRoot, $projectDir);
	}

	/**
	 * Builds HTTP response from given image data
	 */
	protected function buildResponse(
		string $content,
		?string $mime = null,
		?ServerRequestInterface $request = null
	): ResponseInterface {
		// define mime type
		if ($mime === null) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$detectedMime = $finfo !== false ? finfo_buffer($finfo, $content) : false;
			$mime = is_string($detectedMime) && $detectedMime !== '' ? $detectedMime : 'application/octet-stream';
		}

		$activeRequest = $request ?? $this->currentRequest;
		$etag = '"' . md5($content) . '"';
		$ifNoneMatch = $activeRequest?->getHeaderLine('If-None-Match') ?? '';
		$cacheMaxAge = (int) $this->imageCfg->get('cache.lifetime', 0) * 60;

		if (self::matchesIfNoneMatch($ifNoneMatch, $etag)) {
			return (new Response())
				->withStatus(304)
				->withHeader('ETag', $etag)
				->withHeader('Cache-Control', 'max-age=' . $cacheMaxAge . ', public');
		}

		// return http response
		$factory = new StreamFactory();
		$body = $factory->createStream($content);
		$response = new Response();

		return $response
			->withHeader('Content-Type', $mime)
			->withHeader('ETag', $etag)
			->withHeader('Cache-Control', 'max-age=' . $cacheMaxAge . ', public')
			->withBody($body)
			->withHeader('Content-Length', (string) strlen($content));
	}

	public static function matchesIfNoneMatch(string $ifNoneMatchHeader, string $etag): bool
	{
		$header = trim($ifNoneMatchHeader);
		if ($header === '') {
			return false;
		}

		if ($header === '*') {
			return true;
		}

		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}

			if ($candidate !== '' && hash_equals($etag, $candidate)) {
				return true;
			}
		}

		return false;
	}

	protected function isAcceptableRequest(ServerRequestInterface $request, ImageRequest $imageRequest): bool
	{
		if (strtolower($imageRequest->getTemplate()) === 'download') {
			return true;
		}

		$accept = strtolower(trim($request->getHeaderLine('Accept')));
		if ($accept === '') {
			return true;
		}

		return str_contains($accept, 'image/') || str_contains($accept, '*/*');
	}

	protected function sanitizeDownloadFilename(string $filename): string
	{
		$safeFilename = basename(str_replace('\\', '/', $filename));
		$safeFilename = str_replace(['"', "\r", "\n", "\0", ';'], '', $safeFilename);
		$safeFilename = preg_replace('/[\x00-\x1F\x7F]/', '', $safeFilename) ?? '';

		if ($safeFilename === '' || $safeFilename === '.' || $safeFilename === '..') {
			return 'download';
		}

		return $safeFilename;
	}

	private function isPathWithinRoot(string $realCandidate, string $realRootPrefix): bool
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return stripos($realCandidate, $realRootPrefix) === 0;
		}

		return str_starts_with($realCandidate, $realRootPrefix);
	}
}
