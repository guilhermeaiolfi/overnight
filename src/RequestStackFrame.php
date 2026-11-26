<?php

declare(strict_types=1);

namespace ON;

use Psr\Http\Message\ServerRequestInterface;

final class RequestStackFrame
{
	public function __construct(
		public ServerRequestInterface $currentRequest,
		public readonly ?ServerRequestInterface $parentRequest = null
	) {
	}
}
