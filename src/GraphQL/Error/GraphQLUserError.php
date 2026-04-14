<?php

declare(strict_types=1);

namespace ON\GraphQL\Error;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

class GraphQLUserError extends RuntimeException implements ClientAware, ProvidesExtensions
{
	public function __construct(
		string $message,
		protected string $errorCode = 'INTERNAL_ERROR',
		protected ?string $field = null,
		?\Throwable $previous = null
	) {
		parent::__construct($message, 0, $previous);
	}

	public function isClientSafe(): bool
	{
		return true;
	}

	public function getExtensions(): array
	{
		$extensions = ['code' => $this->errorCode];

		if ($this->field !== null) {
			$extensions['field'] = $this->field;
		}

		return $extensions;
	}
}
