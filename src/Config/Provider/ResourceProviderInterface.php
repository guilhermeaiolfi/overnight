<?php

declare(strict_types=1);

namespace ON\Config\Provider;

use Symfony\Component\Config\Resource\ResourceInterface;

interface ResourceProviderInterface
{
	public function getResource(): ResourceInterface;
}
