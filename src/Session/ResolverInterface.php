<?php

declare(strict_types=1);

namespace ON\Session;

interface ResolverInterface
{
	public function resolve(): ?SessionInterface;
}
