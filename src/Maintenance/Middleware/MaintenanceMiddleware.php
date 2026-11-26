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

		return $this->parseIpList($ips);
	}

	private function getTrustedProxies(): array
	{
		$proxies = $this->appConfig->get('maintenance.trusted_proxies', '');

		if (empty($proxies)) {
			$proxies = $_ENV['MAINTENANCE_TRUSTED_PROXIES'] ?? '';
		}

		return $this->parseIpList($proxies);
	}

	/**
	 * @return list<string>
	 */
	private function parseIpList(mixed $ips): array
	{
		if (empty($ips)) {
			return [];
		}

		if (is_array($ips)) {
			$items = $ips;
		} elseif (is_string($ips)) {
			$items = explode(',', $ips);
		} else {
			return [];
		}

		$result = [];
		foreach ($items as $item) {
			if (! is_string($item)) {
				continue;
			}
			$trimmed = trim($item);
			if ($trimmed !== '') {
				$result[] = $trimmed;
			}
		}

		return $result;
	}

	private function isTrustedProxy(string $ip, array $trustedProxies): bool
	{
		foreach ($trustedProxies as $proxy) {
			if ($this->ipMatches($ip, $proxy)) {
				return true;
			}
		}

		return false;
	}

	private function getClientIp(ServerRequestInterface $request): string
	{
		$serverParams = $request->getServerParams();
		$remoteAddr = $serverParams['REMOTE_ADDR'] ?? '';

		if (! is_string($remoteAddr) || ! filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
			return '';
		}

		$trustedProxies = $this->getTrustedProxies();
		if ($trustedProxies === [] || ! $this->isTrustedProxy($remoteAddr, $trustedProxies)) {
			return $remoteAddr;
		}

		if (isset($serverParams['HTTP_X_FORWARDED_FOR']) && is_string($serverParams['HTTP_X_FORWARDED_FOR'])) {
			$hops = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
			for ($i = count($hops) - 1; $i >= 0; $i--) {
				$hop = trim($hops[$i]);
				if (! filter_var($hop, FILTER_VALIDATE_IP)) {
					return '';
				}

				if (! $this->isTrustedProxy($hop, $trustedProxies)) {
					return $hop;
				}
			}

			return trim($hops[0]);
		}

		foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
			if (isset($serverParams[$header]) && is_string($serverParams[$header])) {
				$ip = trim($serverParams[$header]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}

		return $remoteAddr;
	}

	private function ipMatches(string $ip, string $allowed): bool
	{
		if ($ip === '' || $allowed === '') {
			return false;
		}

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
		$parts = explode('/', $cidr);
		if (count($parts) !== 2) {
			return false;
		}

		[$subnet, $bits] = $parts;
		if (! ctype_digit($bits)) {
			return false;
		}

		$bitsInt = (int) $bits;
		if ($bitsInt < 0 || $bitsInt > 32) {
			return false;
		}

		if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return false;
		}

		$ipLong = ip2long($ip);
		$subnetLong = ip2long($subnet);

		if ($ipLong === false || $subnetLong === false) {
			return false;
		}

		if ($bitsInt === 0) {
			return true;
		}

		$mask = -1 << (32 - $bitsInt);

		return ($ipLong & $mask) === ($subnetLong & $mask);
	}
}
