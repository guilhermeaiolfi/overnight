<?php

declare(strict_types=1);

namespace ON\Validation;

use RuntimeException;

final class ValidationFailedException extends RuntimeException
{
	/**
	 * @param array<string, array<int, string>|string> $errors
	 */
	public function __construct(
		string $message,
		private readonly array $errors,
	) {
		parent::__construct($message);
	}

	/**
	 * @param array<string, array<int, string>|string> $errors
	 */
	public static function fromErrors(array $errors): self
	{
		$firstMessage = '';

		foreach ($errors as $messages) {
			$firstMessage = is_array($messages) ? ($messages[0] ?? '') : (string) $messages;

			break;
		}

		return new self($firstMessage ?: 'Validation failed.', $errors);
	}

	/**
	 * @return array<string, array<int, string>|string>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
}
