<?php
namespace ON\Image;

use Intervention\Image\Image;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\StreamFactory;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\EmptyResponse;
use ON\Image\Cache\FileSystem;
use ON\Image\Cache\ImageCacheInterface;
use ON\Image\Encrypter\EncrypterInterface;
use ON\Image\Encrypter\OpenSSL;

class ImageManager implements MiddlewareInterface {

    const MAX_FILENAME_LENGTH = 200;

     /**
     * @var string|null
     */
    private $signatureKey = null;

    private $encrypter = null;

    private $config = null;

    private $imageCache = null;

    private $basePath = null;

    /**
     * Set the signature key used to encode/decode the data.
     */
    public function __construct(
        $config,
        EncrypterInterface $encrypter = null,
        ImageCacheInterface $imageCache = null
    )
    {
        $signatureKey = $config['key'];
        if (!isset($encrypter)) {
            $encrypter = new OpenSSL($signatureKey);
        }
        $this->encrypter = $encrypter;

        $this->signatureKey = $signatureKey;

        $this->basePath = $config['basePath'];

        $this->config = $config;

        if (!isset($imageCache)) {
            $imageCache = new FileSystem($config);
        }
        $this->imageCache = $imageCache;
    }

    public function getUri(string $path, string $template, $options = null): string
    {
        if ($this->signatureKey === null) {
            throw new RuntimeException('No signature key provided!'.
            ' You must instantiate the middleware or assign the key as third argument');
        }

        $token = $this->encrypter->encrypt(["path" => $path, "template" => $template, "options" => $options], $this->signatureKey);
        //$token = chunk_split((string) $token, self::MAX_FILENAME_LENGTH, '/');
        //$token = str_replace('/.', './', $token); //create folders for images

        return $this->imageCache->filename($path, $token);
    }

    /**
     * Process a request and return a response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = null;

        if (strpos($request->getHeaderLine('Accept'), 'image/') === false) {
            $response = $handler->handle($request);
        } else {
            $uri = $request->getUri();
            $path = $uri->getPath();

            if (strpos($path, $this->basePath) === false) {
                return null;
            }

            list($basePath, $token) = explode($this->basePath, $path, 2);

            if ($extensionPos = strrpos($token, '.')) {
                $token = substr($token, 0, $extensionPos);
            }

            $token = $this->imageCache->token($token);

            $payload = $this->encrypter->decrypt($token);

            if (!$payload) {
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
    public function getResponse($token, $template, $filename, $options = null)
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
    public function getImage($token, $template, $filename, $options = null)
    {
        $template = $this->getTemplate($template, $options);
        $path = $this->getImagePath($filename);

        if (!$path) {
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
    public function getOriginal($filename)
    {
        $path = $this->getImagePath($filename);

        return $this->buildResponse(file_get_contents($path));
    }

    /**
     * Get HTTP response of original image as download
     *
     * @param  string $filename
     * @return Psr\Http\Message\ResponseInterface;
     */
    public function getDownload($filename)
    {
        $response = $this->getOriginal($filename);

        return $response->header(
            'Content-Disposition',
            'attachment; filename=' . $filename
        );
    }

    /**
     * Returns corresponding template object from given template name
     *
     * @param  string $template
     * @return mixed
     */
    protected function getTemplate($template, $options = null)
    {
        $template = $this->config["templates"][$template];

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
    protected function getImagePath($filename)
    {
        // find file
        foreach ($this->config["paths"] as $path) {
            // don't allow '..' in filenames

            $image_path = $path . '/' . str_replace('..', '', $filename);
            if (file_exists($image_path) && is_file($image_path)) {
                // file found
                return $image_path;
            }

        }

        // file not found
        return null;
    }

    /**
     * Builds HTTP response from given image data
     *
     * @param  string $content
     * @return Psr\Http\Message\ResponseInterface;
     */
    protected function buildResponse($content)
    {
        // define mime type
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $content);

        // respond with 304 not modified if browser has the image cached
        $etag = md5($content);
        $not_modified = isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag;
        $content = $not_modified ? null : $content;
        $status_code = $not_modified ? 304 : 200;

        // return http response
        $factory = new StreamFactory();
        $body = $factory->createStream($content);
        $response = new Response();

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'max-age=' . ($this->config["cache"]["lifetime"] * 60) . ', public')
            ->withBody($body)
            ->withHeader('Content-Length', strlen($content));

    }
}