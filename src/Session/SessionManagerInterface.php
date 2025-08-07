<?php

declare(strict_types=1);

namespace ON\Session;

interface SessionManagerInterface
{
	public function resolve(): ?SessionInterface;
}
