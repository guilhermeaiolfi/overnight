<?php

declare(strict_types=1);

namespace Tests\ON\Maintenance;

use Laminas\Diactoros\Response\HtmlResponse;
use ON\Config\AppConfig;
use ON\Container\Executor\Executor;
use ON\Container\Executor\ExecutorInterface;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Maintenance\MaintenanceModeInterface;
use ON\Maintenance\Middleware\MaintenanceMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\ON\Fixtures\MaintenanceHandler;

final class MaintenanceMiddlewareTest extends TestCase
{
	private ContainerInterface $container;
	private AppConfig $config;
	private StubMaintenanceMode $app;
	private Executor $executor;
	private MaintenanceHandler $handler;
	private ServerRequestInterface $request;
	private RequestHandlerInterface $requestHandler;

	protected function setUp(): void
	{
		$this->config = new AppConfig([]);
		$this->config->set('controllers.maintenance', MaintenanceHandler::class);

		$this->app = new StubMaintenanceMode();
		$this->app->config = $this->config;

		$this->handler = new MaintenanceHandler();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')
			->willReturnCallback(function (string $class) {
				if ($class === AppConfig::class) {
					return $this->config;
				}
				if ($class === ExecutorInterface::class || $class === Executor::class) {
					return $this->executor;
				}
				if ($class === MaintenanceHandler::class) {
					return $this->handler;
				}
				return null;
			});

		$parameterResolver = new \Invoker\ParameterResolver\ResolverChain([
			new \Invoker\ParameterResolver\TypeHintResolver(),
			new \Invoker\ParameterResolver\NumericArrayResolver(),
			new \Invoker\ParameterResolver\AssociativeArrayResolver(),
			new \Invoker\ParameterResolver\DefaultValueResolver(),
			new TypeHintContainerResolver($this->container),
		]);
		$this->executor = new Executor($parameterResolver, $this->container);

		$this->request = $this->createMock(ServerRequestInterface::class);
		$this->requestHandler = $this->createMock(RequestHandlerInterface::class);
		$this->requestHandler->method('handle')->willReturn(new HtmlResponse('OK'));

		$this->request->method('getServerParams')->willReturn([
			'REMOTE_ADDR' => '127.0.0.1',
		]);
	}

	private function createMiddleware(): MaintenanceMiddleware
	{
		return new MaintenanceMiddleware($this->app, $this->container);
	}

	private function createRequestWithIp(string $ip, ?string $forwardedFor = null): ServerRequestInterface
	{
		$serverParams = ['REMOTE_ADDR' => $ip];
		if ($forwardedFor !== null) {
			$serverParams['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
		}

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getServerParams')->willReturn($serverParams);
		return $request;
	}

	private function setAllowedIps(string $ips): void
	{
		$this->config->set('maintenance.allow_ips', $ips);
	}

	public function testPassesThroughWhenNotInMaintenanceMode(): void
	{
		$this->app->setMaintenanceMode(false);

		$middleware = $this->createMiddleware();
		$response = $middleware->process($this->request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('OK', (string) $response->getBody());
	}

	public function testShowsMaintenancePageWhenMaintenanceModeOn(): void
	{
		$this->app->setMaintenanceMode(true);
		$middleware = $this->createMiddleware();
		$response = $middleware->process($this->request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
		$this->assertTrue($this->handler->wasCalled);
	}

	public function testAllowsWhitelistedIp(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.100');
		$request = $this->createRequestWithIp('192.168.1.100');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRejectsNonWhitelistedIp(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.100');
		$request = $this->createRequestWithIp('10.0.0.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testAllowsWildcardMatch(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.*');
		$request = $this->createRequestWithIp('192.168.1.50');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRejectsWildcardNoMatch(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.*');
		$request = $this->createRequestWithIp('192.168.2.50');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testAllowsCidr24Match(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.0/24');
		$request = $this->createRequestWithIp('192.168.1.100');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRejectsCidrNoMatch(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.0/24');
		$request = $this->createRequestWithIp('192.168.2.100');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testAllowsCidr16Match(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.0.0/16');
		$request = $this->createRequestWithIp('192.168.50.100');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testAllowsCidr8Match(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('10.0.0.0/8');
		$request = $this->createRequestWithIp('10.255.255.255');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testAllowsMultipleCommaSeparatedIps(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('127.0.0.1,192.168.1.100,10.0.0.1');
		$request = $this->createRequestWithIp('10.0.0.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testAllowsMultipleIpFormats(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('127.0.0.1,192.168.1.0/24,10.*.*.*');
		$request = $this->createRequestWithIp('10.5.6.7');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testDetectsIpFromXForwardedFor(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('203.0.113.50');
		$request = $this->createRequestWithIp('127.0.0.1', '203.0.113.50, 10.0.0.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testDetectsIpFromXRealIp(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('203.0.113.50');

		$serverParams = [
			'REMOTE_ADDR' => '127.0.0.1',
			'HTTP_X_REAL_IP' => '203.0.113.50',
		];
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getServerParams')->willReturn($serverParams);

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testHandlesEmptyWhitelist(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($this->request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testHandlesInvalidCidrGracefully(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('invalid-cidr');
		$request = $this->createRequestWithIp('192.168.1.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testHandlesInvalidIpGracefully(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.100');

		$serverParams = ['REMOTE_ADDR' => 'not-an-ip'];
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getServerParams')->willReturn($serverParams);

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(503, $response->getStatusCode());
	}

	public function testAllowsLocalhostByExactMatch(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('127.0.0.1');
		$request = $this->createRequestWithIp('127.0.0.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRespectsEnvVariableWhenConfigEmpty(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->config->set('maintenance.allow_ips', '');

		$_ENV['MAINTENANCE_ALLOW_IPS'] = '10.0.0.1';
		$request = $this->createRequestWithIp('10.0.0.1');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		unset($_ENV['MAINTENANCE_ALLOW_IPS']);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testConfigTakesPrecedenceOverEnv(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.100');
		$_ENV['MAINTENANCE_ALLOW_IPS'] = '10.0.0.1';

		$request = $this->createRequestWithIp('192.168.1.100');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		unset($_ENV['MAINTENANCE_ALLOW_IPS']);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testHandlesIpsWithSpaces(): void
	{
		$this->app->setMaintenanceMode(true);
		$this->setAllowedIps('192.168.1.100, 192.168.1.101, 192.168.1.102');
		$request = $this->createRequestWithIp('192.168.1.101');

		$middleware = $this->createMiddleware();
		$response = $middleware->process($request, $this->requestHandler);

		$this->assertSame(200, $response->getStatusCode());
	}
}

class StubMaintenanceMode implements MaintenanceModeInterface
{
	private bool $maintenanceMode = true;
	public ?AppConfig $config = null;

	public function setMaintenanceMode(bool $mode): void
	{
		$this->maintenanceMode = $mode;
	}

	public function isMaintenanceMode(): bool
	{
		return $this->maintenanceMode;
	}
}
