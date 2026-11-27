<?php

declare(strict_types=1);

namespace ON\Runtime;

use function Laminas\Diactoros\marshalProtocolVersionFromSapi;
use function Laminas\Diactoros\normalizeUploadedFiles;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\UriFactory;
use ON\Application;
use ON\Event\EventsExtension;
use ON\Event\NamedEvent;
use ON\Middleware\PipelineExtension;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swoole\Http\Server;

class SwooleRuntime implements RuntimeInterface
{
	public function __construct(
		protected string $host = '127.0.0.1',
		protected int $port = 9501,
		protected array $settings = [],
		/** @var (callable(string, int): object)|null */
		protected $serverFactory = null,
	) {
	}

	public function run(Application $app): int
	{
		if (! $app->hasExtension(PipelineExtension::class)) {
			throw new RuntimeException('SwooleRuntime requires PipelineExtension.');
		}

		$server = $this->createServer();
		if ($this->settings !== []) {
			$server->set($this->settings);
		}
		$server->on('request', fn (object $request, object $response) => $this->handleRequest($app, $request, $response));

		$events = $app->hasExtension(EventsExtension::class) ? $app->ext(EventsExtension::class) : null;
		$events?->dispatch(new NamedEvent('core.run'));

		try {
			$server->start();
		} finally {
			$events?->dispatch(new NamedEvent('core.end'));
		}

		return 0;
	}

	private function createServer(): object
	{
		if ($this->serverFactory !== null) {
			return ($this->serverFactory)($this->host, $this->port);
		}
		if (! class_exists(Server::class)) {
			throw new RuntimeException('SwooleRuntime requires the Swoole PHP extension.');
		}

		return new Server($this->host, $this->port);
	}

	public function handleRequest(Application $app, object $swooleRequest, object $swooleResponse): void
	{
		$request = $this->createRequest($swooleRequest);
		$response = $app->ext(PipelineExtension::class)->handle($request);

		$swooleResponse->status($response->getStatusCode(), $response->getReasonPhrase());
		foreach ($response->getHeaders() as $name => $values) {
			$swooleResponse->header($name, count($values) === 1 ? $values[0] : array_values($values));
		}

		$body = $response->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}
		$swooleResponse->end((string) $body);
	}

	private function createRequest(object $request): ServerRequestInterface
	{
		$server = array_change_key_case($request->server ?? [], CASE_UPPER);
		$headers = $request->header ?? [];
		foreach ($headers as $name => $value) {
			$key = strtoupper(str_replace('-', '_', $name));
			$value = is_array($value) ? implode(', ', $value) : $value;
			$server['HTTP_' . $key] = $value;
			if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
				$server[$key] = $value;
			}
		}

		$body = new Stream('php://temp', 'w+b');
		if (method_exists($request, 'rawContent')) {
			$body->write((string) $request->rawContent());
			$body->rewind();
		}

		$post = $request->post ?? [];

		return new ServerRequest(
			serverParams: $server,
			uploadedFiles: normalizeUploadedFiles($request->files ?? []),
			uri: $this->createUri($server, $headers),
			method: $server['REQUEST_METHOD'] ?? 'GET',
			body: $body,
			headers: $headers,
			cookieParams: $request->cookie ?? [],
			queryParams: $request->get ?? [],
			parsedBody: $post === [] ? null : $post,
			protocol: marshalProtocolVersionFromSapi($server),
		);
	}

	private function createUri(array $server, array $headers): Uri
	{
		$https = strtolower((string) ($server['HTTPS'] ?? ''));
		$forwardedProto = explode(',', $server['HTTP_X_FORWARDED_PROTO'] ?? '', 2)[0];
		$isHttps = ! in_array($https, ['', 'off', '0'], true) || strtolower(trim($forwardedProto)) === 'https';

		$host = $this->host;
		if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}
		$requestUri = $server['REQUEST_URI'] ?? '/';
		$query = explode('?', $requestUri, 2)[1] ?? '';

		return UriFactory::createFromSapi([
			'HTTPS' => $isHttps ? 'on' : 'off',
			'SERVER_NAME' => $host,
			'SERVER_PORT' => $this->port,
			'REQUEST_URI' => $requestUri,
			'QUERY_STRING' => $server['QUERY_STRING'] ?? $query,
		], $headers);
	}
}
