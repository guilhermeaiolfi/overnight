<?php

declare(strict_types=1);

namespace ON\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestContext
{
	public function __construct(
		public readonly ?ServerRequestInterface $parentRequest = null
	) {
	}
}
