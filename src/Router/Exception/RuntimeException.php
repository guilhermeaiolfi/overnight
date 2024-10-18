<?php

declare(strict_types=1);

namespace ON\Router\Exception;

use RuntimeException as PhpRuntimeException;

class RuntimeException extends PhpRuntimeException implements ExceptionInterface
{
}
