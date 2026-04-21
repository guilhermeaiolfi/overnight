<?php

declare(strict_types=1);

namespace ON\Maintenance\Middleware;

use ON\Config\AppConfig;
use ON\Container\Executor\ExecutorInterface;
use ON\Maintenance\MaintenanceModeInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MaintenanceMiddleware implements MiddlewareInterface
{
	protected AppConfig $appConfig;

	public function __construct(
		protected MaintenanceModeInterface $maintenance,
		protected ContainerInterface $container,
		?AppConfig $appConfig = null
	) {
		$this->appConfig = $appConfig ?? $container->get(AppConfig::class);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->maintenance->isMaintenanceMode()) {
			if ($this->isIpAllowed($request)) {
				return $handler->handle($request);
			}

			$middleware = $this->appConfig->get("controllers.maintenance");
			$method = 'handle';
			if (str_contains($middleware, "::")) {
				[$middleware, $method] = explode("::", $middleware);
			}
			$instance = $this->container->get($middleware);
			$executor = $this->container->get(ExecutorInterface::class);

			$result = $executor->execute(
				[ $instance, $method ],
				[
					ServerRequestInterface::class => $request,
					RequestHandlerInterface::class => $handler,
				]
			);

			return $result;
		}

		return $handler->handle($request);
	}

	private function isIpAllowed(ServerRequestInterface $request): bool
	{
		$allowedIps = $this->getAllowedIps();

		if (empty($allowedIps)) {
			return false;
		}

		$clientIp = $this->getClientIp($request);

		foreach ($allowedIps as $allowed) {
			if ($this->ipMatches($clientIp, $allowed)) {
				return true;
			}
		}

		return false;
	}

	private function getAllowedIps(): array
	{
		$ips = $this->appConfig->get('maintenance.allow_ips', '');

		if (empty($ips)) {
			$ips = $_ENV['MAINTENANCE_ALLOW_IPS'] ?? '';
		}

		if (empty($ips)) {
			return [];
		}

		return array_map('trim', explode(',', $ips));
	}

	private function getClientIp(ServerRequestInterface $request): string
	{
		$serverParams = $request->getServerParams();

		$headers = [
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CF_CONNECTING_IP',
			'REMOTE_ADDR',
		];

		foreach ($headers as $header) {
			if (isset($serverParams[$header])) {
				$ip = $serverParams[$header];
				if (str_contains($ip, ',')) {
					$ip = trim(explode(',', $ip)[0]);
				}
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}

		return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	private function ipMatches(string $ip, string $allowed): bool
	{
		if ($ip === $allowed) {
			return true;
		}

		if (str_contains($allowed, '/')) {
			return $this->ipInCidr($ip, $allowed);
		}

		if (str_contains($allowed, '*')) {
			$segments = explode('.', $allowed);
			$patternParts = [];
			foreach ($segments as $segment) {
				if ($segment === '*') {
					$patternParts[] = '[0-9]+';
				} else {
					$patternParts[] = preg_quote($segment, '/');
				}
			}
			$pattern = '/^' . implode('\.', $patternParts) . '$/';
			return (bool) preg_match($pattern, $ip);
		}

		return false;
	}

	private function ipInCidr(string $ip, string $cidr): bool
	{
		[$subnet, $bits] = explode('/', $cidr);

		if (! filter_var($ip, FILTER_VALIDATE_IP) || ! filter_var($subnet, FILTER_VALIDATE_IP)) {
			return false;
		}

		$ip = ip2long($ip);
		$subnet = ip2long($subnet);

		if ($ip === false || $subnet === false) {
			return false;
		}

		$mask = -1 << (32 - (int) $bits);

		return ($ip & $mask) === ($subnet & $mask);
	}
}
