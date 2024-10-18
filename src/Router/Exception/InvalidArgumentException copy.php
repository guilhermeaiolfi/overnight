<?php

declare(strict_types=1);

namespace ON\Router\Exception;

use InvalidArgumentException as PhpInvalidArgumentException;

/** @final */
class InvalidArgumentException extends PhpInvalidArgumentException implements ExceptionInterface
{
}
