<?php

declare(strict_types=1);

namespace ON\View\Plates\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class InvalidExtensionException extends RuntimeException implements
	ExceptionInterface,
	ContainerExceptionInterface
{
}
