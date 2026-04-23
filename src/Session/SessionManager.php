<?php

declare(strict_types=1);

namespace ON\Session;

class SessionManager implements SessionManagerInterface
{
	public function __construct(
		protected ResolverInterface $resolver
	) {

	}

	public function resolve(): ?SessionInterface
	{
		return $this->resolver->resolve();
	}
}
