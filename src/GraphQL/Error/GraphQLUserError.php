<?php

declare(strict_types=1);

namespace ON\GraphQL\Error;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

class GraphQLUserError extends RuntimeException implements ClientAware, ProvidesExtensions
{
	protected array $validationErrors = [];

	public function __construct(
		string $message,
		protected string $errorCode = 'INTERNAL_ERROR',
		protected ?string $field = null,
		?\Throwable $previous = null
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Create a validation error with all field errors.
	 *
	 * @param array<string, string[]> $fieldErrors Field name => array of error messages
	 */
	public static function validationFailed(array $fieldErrors): self
	{
		$firstField = array_key_first($fieldErrors);
		$firstMessage = $fieldErrors[$firstField][0] ?? 'Validation failed';

		$error = new self($firstMessage, 'VALIDATION_ERROR', $firstField);
		$error->validationErrors = $fieldErrors;

		return $error;
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

		if (!empty($this->validationErrors)) {
			$extensions['validationErrors'] = $this->validationErrors;
		}

		return $extensions;
	}
}
