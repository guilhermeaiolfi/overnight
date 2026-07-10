<?php

declare(strict_types=1);

namespace ON\Mapper\Exception;

use RuntimeException;
use Throwable;

class ConversionException extends RuntimeException
{
	public function __construct(
		string $message,
		private readonly ?string $field = null,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getField(): ?string
	{
		return $this->field;
	}
}
