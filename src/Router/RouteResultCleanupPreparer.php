<?php

declare(strict_types=1);

namespace ON\Router;

use ON\Http\RequestContext;
use ON\Middleware\RequestPreparerInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteResultCleanupPreparer implements RequestPreparerInterface
{
	public function prepare(ServerRequestInterface $request): ServerRequestInterface
	{
		$requestContext = $request->getAttribute(RequestContext::class);

		if (! $requestContext instanceof RequestContext || $requestContext->parentRequest === null) {
			return $request;
		}

		$existingRouteResult = $request->getAttribute(RouteResult::class);

		if ($existingRouteResult instanceof RouteResult) {
			foreach ($existingRouteResult->getMatchedParams() as $param => $_value) {
				$request = $request->withoutAttribute((string) $param);
			}

			$request = $request->withoutAttribute(RouteResult::class);
		}

		return $request;
	}
}
