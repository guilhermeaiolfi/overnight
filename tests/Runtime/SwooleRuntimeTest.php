<?php

declare(strict_types=1);

namespace Tests\ON\Runtime;

use Closure;
use FilesystemIterator;
use Laminas\Diactoros\Response\TextResponse;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Console\ConsoleExtension;
use ON\Container\ContainerExtension;
use ON\DB\DatabaseExtension;
use ON\DB\DatabaseStoreInterface;
use ON\Event\EventsExtension;
use ON\Event\NamedEvent;
use ON\Middleware\PipelineExtension;
use ON\RequestStackInterface;
use ON\Runtime\ConsoleRuntime;
use ON\Runtime\HttpRuntime;
use ON\Runtime\SwooleRuntime;
use ON\Swoole\SwooleDatabaseStore;
use ON\Swoole\SwooleExtension;
use ON\Swoole\SwooleRequestStack;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Http\Server;

#[BackupGlobals(true)]
final class SwooleRuntimeTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;
	private ?Application $previousApplication;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->previousApplication = Application::$instance;
		$this->projectDir = sys_get_temp_dir() . '/overnight-swoole-runtime-' . bin2hex(random_bytes(8));
		mkdir($this->projectDir . '/config', recursive: true);
		file_put_contents($this->projectDir . '/config/pipeline.php', '<?php return static function (): void {};');
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = $this->previousApplication;
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->projectDir);
	}

	public function testRuntimeRequiresTheSwooleExtensionWithoutAFactory(): void
	{
		if (class_exists(Server::class)) {
			$this->markTestSkipped('Swoole is installed.');
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('SwooleRuntime requires the Swoole PHP extension.');
		$this->createApplication()->run('swoole');
	}

	public function testRuntimeRequiresPipelineBeforeCreatingTheServer(): void
	{
		$app = new Application([
			'paths' => ['project' => $this->projectDir],
			'extensions' => [],
			'disable_dotenv' => true,
			'debug' => true,
		]);

		$runtime = new SwooleRuntime(serverFactory: fn () => $this->fail('Server factory should not run without a pipeline.'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('SwooleRuntime requires PipelineExtension.');
		$app->run($runtime);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testSwooleRuntimeServesJsonAndUploadedFilesThroughThePipeline(): void
	{
		if (! class_exists(Coroutine::class)) {
			class_alias(RuntimeCoroutineStub::class, Coroutine::class);
		}

		$upload = $this->projectDir . '/report.txt';
		file_put_contents($upload, 'report');
		$file = ['tmp_name' => $upload, 'size' => 6, 'error' => UPLOAD_ERR_OK, 'name' => 'report.txt', 'type' => 'text/plain'];
		$json = new SwooleTestRequest(
			server: [
				'request_method' => 'PATCH',
				'request_uri' => '/records%20new?ignored=yes',
				'query_string' => 'page=2',
				'server_protocol' => 'HTTP/2',
				'remote_addr' => '192.0.2.1',
				'server_port' => 9502,
			],
			header: ['Host' => 'example.test:8443', 'X-Forwarded-Proto' => 'https', 'content-type' => 'application/json'],
			get: ['page' => '2'],
			cookie: ['session' => 'demo'],
			raw: '{"name":"record"}',
		);
		$form = new SwooleTestRequest(
			server: ['request_method' => 'POST', 'request_uri' => '/upload?source=form'],
			header: ['content-type' => 'multipart/form-data; boundary=demo'],
			get: ['source' => 'form'],
			post: ['label' => 'report'],
			files: [
				'primary' => $file,
				'attachments' => ['report' => [$file]],
				'grouped' => array_map(static fn ($value): array => ['report' => $value], $file),
			],
		);
		$jsonResponse = new SwooleTestResponse();
		$formResponse = new SwooleTestResponse();
		$server = new SwooleTestServer(static function (Closure $onRequest) use ($json, $form, $jsonResponse, $formResponse): void {
			$onRequest($json, $jsonResponse);
			$onRequest($form, $formResponse);
		});
		$app = $this->createApplication([
			'host' => '::1',
			'port' => 9502,
			'settings' => ['worker_num' => 1],
			'serverFactory' => function (string $host, int $port) use ($server): SwooleTestServer {
				$this->assertSame('::1', $host);
				$this->assertSame(9502, $port);

				return $server;
			},
		]);

		$this->assertTrue($app->isCli());
		$this->assertSame(['swoole', 'http', 'console'], array_keys($app->getRuntimes()));
		$this->assertInstanceOf(HttpRuntime::class, $app->getRuntime('http'));
		$this->assertInstanceOf(ConsoleRuntime::class, $app->getRuntime('console'));
		$this->assertInstanceOf(SwooleRuntime::class, $app->getRuntime('swoole'));

		$container = $app->ext(ContainerExtension::class)->getContainer();
		$this->assertInstanceOf(SwooleRequestStack::class, $container->get(RequestStackInterface::class));
		$this->assertInstanceOf(SwooleDatabaseStore::class, $container->get(DatabaseStoreInterface::class));

		$lifecycle = [];
		$events = $app->ext(EventsExtension::class);
		foreach (['core.run', 'core.end'] as $name) {
			$events->registerListener($name, function (NamedEvent $event) use ($app, &$lifecycle): void {
				$this->assertSame('swoole', $app->getActiveRuntime());
				$lifecycle[] = $event->eventName();
			});
		}
		$beforeBodyParsing = [];
		$events->registerListener('core.request', static function (NamedEvent $event) use (&$beforeBodyParsing): void {
			$beforeBodyParsing[] = $event->getSubject()->getParsedBody();
		});

		$requests = [];
		$app->ext(PipelineExtension::class)->pipe(function (ServerRequestInterface $request) use (&$requests, &$lifecycle): ResponseInterface {
			$requests[] = $request;
			$lifecycle[] = 'request';
			$response = new TextResponse('saved', 201);
			$response->getBody()->seek(2);

			return $response
				->withHeader('X-Handled-By', 'pipeline')
				->withHeader('Set-Cookie', $request->getMethod() === 'PATCH' ? ['a=1; Path=/', 'b=2; Path=/'] : 'form=1; Path=/');
		}, priority: 100);

		$this->assertSame(0, $app->run('swoole'));
		$this->assertNull($app->getActiveRuntime());
		$this->assertSame(['worker_num' => 1], $server->settings);
		$this->assertSame(['core.run', 'request', 'request', 'core.end'], $lifecycle);
		$this->assertSame([null, ['label' => 'report']], $beforeBodyParsing);
		[$jsonRequest, $formRequest] = $requests;

		$this->assertSame('PATCH', $jsonRequest->getMethod());
		$this->assertSame('https://example.test:8443/records%20new?page=2', (string) $jsonRequest->getUri());
		$this->assertSame('2', $jsonRequest->getProtocolVersion());
		$this->assertSame(['page' => '2'], $jsonRequest->getQueryParams());
		$this->assertSame(['session' => 'demo'], $jsonRequest->getCookieParams());
		$this->assertSame('application/json', $jsonRequest->getHeaderLine('Content-Type'));
		$this->assertSame('{"name":"record"}', (string) $jsonRequest->getBody());
		$this->assertSame(['name' => 'record'], $jsonRequest->getParsedBody());
		$this->assertSame([
			'REQUEST_METHOD' => 'PATCH',
			'REQUEST_URI' => '/records%20new?ignored=yes',
			'QUERY_STRING' => 'page=2',
			'SERVER_PROTOCOL' => 'HTTP/2',
			'REMOTE_ADDR' => '192.0.2.1',
			'SERVER_PORT' => 9502,
			'HTTP_HOST' => 'example.test:8443',
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'HTTP_CONTENT_TYPE' => 'application/json',
			'CONTENT_TYPE' => 'application/json',
		], $jsonRequest->getServerParams());

		$this->assertSame('POST', $formRequest->getMethod());
		$this->assertSame('http://[::1]:9502/upload?source=form', (string) $formRequest->getUri());
		$this->assertSame(['source' => 'form'], $formRequest->getQueryParams());
		$this->assertSame(['label' => 'report'], $formRequest->getParsedBody());
		$files = $formRequest->getUploadedFiles();
		foreach ([$files['primary'], $files['attachments']['report'][0], $files['grouped']['report']] as $uploadedFile) {
			$this->assertInstanceOf(UploadedFileInterface::class, $uploadedFile);
			$this->assertSame(UPLOAD_ERR_OK, $uploadedFile->getError());
			$this->assertSame(6, $uploadedFile->getSize());
			$this->assertSame('report.txt', $uploadedFile->getClientFilename());
			$this->assertSame('text/plain', $uploadedFile->getClientMediaType());
			$this->assertSame('report', (string) $uploadedFile->getStream());
			$uploadedFile->getStream()->close();
		}

		foreach ([$jsonResponse, $formResponse] as $response) {
			$this->assertSame([201, 'Created'], $response->status);
			$this->assertSame('pipeline', $response->headers['X-Handled-By']);
			$this->assertSame('saved', $response->body);
		}
		$this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $jsonResponse->headers['Set-Cookie']);
		$this->assertSame('form=1; Path=/', $formResponse->headers['Set-Cookie']);
	}

	public function testEndEventIsDispatchedWhenTheServerThrows(): void
	{
		$server = new SwooleTestServer(static function (): never {
			throw new RuntimeException('Server failed.');
		});
		$app = $this->createApplication(['serverFactory' => static fn (): SwooleTestServer => $server]);
		$ended = false;
		$app->ext(EventsExtension::class)->registerListener('core.end', static function () use (&$ended): void {
			$ended = true;
		});

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Server failed.');

		try {
			$app->run('swoole');
		} finally {
			$this->assertTrue($ended);
			$this->assertNull($app->getActiveRuntime());
		}
	}

	private function createApplication(array $swooleOptions = []): Application
	{
		return new Application([
			'paths' => ['project' => $this->projectDir],
			'disable_dotenv' => true,
			'debug' => true,
			'extensions' => [
				SwooleExtension::class => $swooleOptions,
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				EventsExtension::class => [],
				PipelineExtension::class => [],
				ConsoleExtension::class => [],
				DatabaseExtension::class => [],
			],
		]);
	}
}

final class RuntimeCoroutineStub
{
	public static function getCid(): int
	{
		return -1;
	}
}

final class SwooleTestServer
{
	public array $settings = [];
	private array $callbacks = [];

	public function __construct(private Closure $serve)
	{
	}

	public function set(array $settings): void
	{
		$this->settings = $settings;
	}

	public function on(string $event, Closure $callback): void
	{
		$this->callbacks[$event] = $callback;
	}

	public function start(): void
	{
		($this->serve)($this->callbacks['request']);
	}
}

final class SwooleTestRequest
{
	public function __construct(
		public array $server,
		public array $header,
		public array $get = [],
		public array $post = [],
		public array $cookie = [],
		public array $files = [],
		private string $raw = '',
	) {
	}

	public function rawContent(): string
	{
		return $this->raw;
	}
}

final class SwooleTestResponse
{
	public array $status;
	public array $headers = [];
	public string $body;

	public function status(int $code, string $reason): void
	{
		$this->status = [$code, $reason];
	}

	public function header(string $name, string|array $value): void
	{
		$this->headers[$name] = $value;
	}

	public function end(string $body): void
	{
		$this->body = $body;
	}
}
