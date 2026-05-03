<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Interfaces\ModifierInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\StreamFactory;
use ON\Image\Cache\FileSystem;
use ON\Image\Cache\ImageCacheInterface;
use ON\Image\Encrypter\EncrypterInterface;
use ON\Image\Encrypter\OpenSSL;
use ON\FS\DirectoryPathInterface;
use ON\FS\FilePathInterface;
use ON\FS\Path;
use ON\FS\PathFile;
use ON\FS\PathRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class ImageManager implements MiddlewareInterface
{
	public const MAX_FILENAME_LENGTH = 200;

	private ?string $signatureKey = null;

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
		protected ?ImageCacheInterface $imageCache = null
	) {
		$signatureKey = $imageCfg->get('key', $_ENV['APP_SALT'] ?? '');

		if (! isset($encrypter)) {
			$this->encrypter = new OpenSSL($signatureKey);
		}

		$this->signatureKey = $signatureKey;

		if (! isset($imageCache)) {
			$this->imageCache = new FileSystem($imageCfg, $this->paths->get('public'));
		}
	}

	public function getUri(string $path, string $template, mixed $options = null): string
	{

		if ($this->signatureKey === null) {
			throw new RuntimeException('No signature key provided!'.
			' You must instantiate the middleware or assign the key as third argument');
		}

		// TODO: what is the best approach here?
		// If we simply return the image, it won't be the size needed
		// for the moment, converting the base image to what we need
		// seems the best approach
		if (! $this->imageExists($path)) {
			//return $this->imageCfg->get("404ImagePath");
			$path = $this->imageCfg->get("404ImagePath");
		}
		$token = $this->encrypter->encrypt(["path" => $path, "template" => $template, "options" => $options]);
		//$token = chunk_split((string) $token, self::MAX_FILENAME_LENGTH, '/');
		//$token = str_replace('/.', './', $token); //create folders for images

		return $this->imageCache->publicAsset($path, $token)->uri();
	}

	protected function imageExists(string $filename): bool
	{
		return $this->getImagePath($filename) !== null;
	}

	/**
	 * Process a request and return a response.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = null;

		$imageBasePath = '/' . $this->imageCfg->publicImagesUriPath() . '/';
		if (strpos($request->getHeaderLine('Accept'), 'image/') === false) {
			$response = $handler->handle($request);
		} else {
			$uri = $request->getUri();
			$path = $uri->getPath();


			// if the path is not in the images folder, ignore, we shouldn't handle
			if (strpos($path, $imageBasePath) === false) {
				return $handler->handle($request);
			}

			[$basePath, $token] = explode($imageBasePath, $path, 2);

			if ($extensionPos = strrpos($token, '.')) {
				$token = substr($token, 0, $extensionPos);
			}

			$token = $this->imageCache->token($token);

			$payload = $this->encrypter->decrypt($token);

			if (! $payload) {
				$response = $handler->handle($request);
			} else {
				$response = $this->getResponse($token, $payload['template'], $payload['path'], $payload['options']);
			}
		}

		return $response;
	}

	/**
	 * Get HTTP response of either original image file or
	 * template applied file.
	 *
	 * @param  string $template
	 * @param  string $filename
	 * @return Psr\Http\Message\ResponseInterface;
	 */
	public function getResponse(string $token, string $template, string $filename, mixed $options = null): ResponseInterface
	{
		switch (strtolower($template)) {
			case 'original':
				return $this->getOriginal($filename);

			case 'download':
				return $this->getDownload($filename);

			default:
				return $this->getImage($token, $template, $filename, $options);
		}
	}

	/**
	 * Get HTTP response of template applied image file
	 *
	 * @param  string $template
	 * @param  string $filename
	 * @return Psr\Http\Message\ResponseInterface;
	 */
	public function getImage(string $token, string $template, string $filename, mixed $options = null): ResponseInterface
	{
		$template = $this->getTemplate($template, $options);
		$path = $this->getImagePath($filename);


		if (! $path) {
			return new EmptyResponse(404);
		}
		if ($template instanceof ResponseInterface) {
			return $template;
		}

		$content = $this->imageCache->get($token, $template, $path);

		return $this->buildResponse($content);
	}

	/**
	 * Get HTTP response of original image file
	 *
	 * @param  string $filename
	 * @return Psr\Http\Message\ResponseInterface;
	 */
	public function getOriginal(string $filename): ResponseInterface
	{
		$path = $this->getImagePath($filename);
		if ($path === null) {
			return new EmptyResponse(404);
		}

		return $this->buildResponse((string) file_get_contents($path->absolute()));
	}

	/**
	 * Get HTTP response of original image as download
	 *
	 * @param  string $filename
	 * @return Psr\Http\Message\ResponseInterface;
	 */
	public function getDownload(string $filename): ResponseInterface
	{
		$response = $this->getOriginal($filename);

		return $response->withHeader(
			'Content-Disposition',
			'attachment; filename=' . basename($filename)
		);
	}

	/**
	 * Returns corresponding template object from given template name
	 *
	 * @param  string $template
	 * @return mixed
	 */
	protected function getTemplate(string $template, mixed $options = null): ResponseInterface|callable|ModifierInterface
	{
		$template = $this->imageCfg->get("templates.{$template}");

		switch (true) {
			// closure template found
			case is_callable($template):
				return $template;

				// filter template found
			case class_exists($template):
				if (isset($options)) {
					return new $template($options);
				}

				return new $template(null);


			default:
				// template not found
				return new EmptyResponse(404);

				break;
		}
	}

	/**
	 * Returns full image path from given filename
	 *
	 * @param  string $filename
	 * @return string
	 */
	protected function getImagePath(string $filename): ?FilePathInterface
	{
		$normalizedFilename = (string) $filename;
		if (Path::isAbsoluteString($normalizedFilename)) {
			$imagePath = PathFile::from($normalizedFilename);
			if (file_exists($imagePath->absolute()) && is_file($imagePath->absolute())) {
				return $imagePath;
			}
		}

		$sanitizedFilename = str_replace('..', '', $normalizedFilename);
		foreach ($this->getSourceRoots() as $root) {
			$imagePath = PathFile::from($sanitizedFilename, $root);
			if (file_exists($imagePath->absolute()) && is_file($imagePath->absolute())) {
				return $imagePath;
			}
		}

		return null;
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
	 *
	 * @param  string $content
	 * @return Psr\Http\Message\ResponseInterface;
	 */
	protected function buildResponse(string $content): ResponseInterface
	{
		// define mime type
		$mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $content);

		// respond with 304 not modified if browser has the image cached
		$etag = md5($content);
		$not_modified = isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag;

		if ($not_modified) {
			return (new Response())
				->withStatus(304)
				->withHeader('ETag', $etag)
				->withHeader('Cache-Control', 'max-age=' . ($this->imageCfg->get("cache.lifetime") * 60) . ', public');
		}

		// return http response
		$factory = new StreamFactory();
		$body = $factory->createStream($content);
		$response = new Response();

		return $response
			->withHeader('Content-Type', $mime)
			->withHeader('ETag', $etag)
			->withHeader('Cache-Control', 'max-age=' . ($this->imageCfg->get("cache.lifetime") * 60) . ', public')
			->withBody($body)
			->withHeader('Content-Length', (string) strlen($content));

	}
}
