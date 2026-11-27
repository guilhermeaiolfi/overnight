<?php

declare(strict_types=1);

namespace Tests\ON\Handler;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use ON\Config\AppConfig;
use ON\Container\ErrorResponseGeneratorFactory;
use ON\Container\NotFoundHandlerFactory;
use ON\Container\ServerRequestErrorResponseGeneratorFactory;
use ON\Container\WhoopsPageHandlerFactory;
use ON\Middleware\OutputTypeMiddleware;
use ON\Middleware\RequestForwarderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Whoops\Handler\PrettyPageHandler;

final class ErrorResponseGeneratorTest extends TestCase
{
	#[DataProvider('debugSettings')]
	public function testRunTimeAndRequestErrorsHonorTheDebugSetting(?string $setting, bool $showsDetails): void
	{
		$previous = $_ENV['APP_DEBUG'] ?? null;
		if ($setting === null) {
			unset($_ENV['APP_DEBUG']);
		} else {
			$_ENV['APP_DEBUG'] = $setting;
		}

		try {
			$container = $this->createStub(ContainerInterface::class);
			$container->method('get')->with(ResponseFactoryInterface::class)->willReturn(new ResponseFactory());
			$runtimeError = (new ErrorResponseGeneratorFactory())($container);
			$requestError = (new ServerRequestErrorResponseGeneratorFactory())($container);
			$error = new RuntimeException('Internal diagnostic details');

			foreach ([$runtimeError($error, new ServerRequest(), new Response()), $requestError($error)] as $response) {
				$this->assertSame(500, $response->getStatusCode());
				$this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
				$this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
				$this->assertSame($showsDetails, str_contains((string) $response->getBody(), $error->getMessage()));
			}
		} finally {
			if ($previous === null) {
				unset($_ENV['APP_DEBUG']);
			} else {
				$_ENV['APP_DEBUG'] = $previous;
			}
		}
	}

	public static function debugSettings(): array
	{
		return [
			'unset' => [null, false],
			'disabled' => ['false', false],
			'enabled' => ['true', true],
		];
	}

	public function testNotFoundFactoryProvidesATextResponseWithoutACustomPage(): void
	{
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[AppConfig::class, new AppConfig()],
			[RequestForwarderInterface::class, $this->createStub(RequestForwarderInterface::class)],
			[ResponseInterface::class, new ResponseFactory()],
		]);
		$handler = (new NotFoundHandlerFactory())($container);
		$request = (new ServerRequest())->withAttribute(OutputTypeMiddleware::class, 'html');

		$this->assertSame(404, $handler->handle($request)->getStatusCode());
	}

	public function testWhoopsCanBeInstantiatedWithoutTheOptionalConfig(): void
	{
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$this->assertInstanceOf(PrettyPageHandler::class, (new WhoopsPageHandlerFactory())($container));
	}
}
