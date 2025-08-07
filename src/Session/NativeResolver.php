<?php

declare(strict_types=1);

namespace ON\Session;

class NativeResolver implements ResolverInterface
{
	public function __construct(
		protected SessionConfig $sessionConfig
	) {

	}

	public function resolve(): ?SessionInterface
	{
		$options = $this->sessionConfig->get(self::class, []);

		return new NativeSession($options);
	}
}
