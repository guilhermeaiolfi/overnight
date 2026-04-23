<?php

declare(strict_types=1);

namespace ON\RestApi\Error;

class RestApiError extends \RuntimeException
{
	protected array $validationErrors = [];

	public function __construct(
		string $message,
		protected string $errorCode = 'INTERNAL_ERROR',
		protected ?string $field = null,
		protected int $httpStatus = 500,
		?\Throwable $previous = null
	) {
		parent::__construct($message, 0, $previous);
	}

	public static function validationFailed(array $fieldErrors): self
	{
		$firstMessage = '';
		foreach ($fieldErrors as $field => $messages) {
			$firstMessage = is_array($messages) ? $messages[0] : $messages;
			break;
		}

		$error = new self(
			$firstMessage ?: 'Validation failed.',
			'VALIDATION_ERROR',
			array_key_first($fieldErrors),
			400
		);
		$error->validationErrors = $fieldErrors;

		return $error;
	}

	public static function notFound(string $message = 'Item not found'): self
	{
		return new self($message, 'NOT_FOUND', null, 404);
	}

	public static function collectionNotFound(string $collection): self
	{
		return new self("Collection '{$collection}' not found.", 'COLLECTION_NOT_FOUND', null, 404);
	}

	public static function invalidJson(): self
	{
		return new self('Invalid JSON in request body.', 'INVALID_JSON', null, 400);
	}

	public static function methodNotAllowed(): self
	{
		return new self('Method not allowed.', 'METHOD_NOT_ALLOWED', null, 405);
	}

	public static function serviceUnavailable(): self
	{
		return new self('No database resolver configured.', 'SERVICE_UNAVAILABLE', null, 503);
	}

	public static function fileHandlerMissing(string $fieldName): self
	{
		return new self(
			"No file handler configured for field '{$fieldName}'.",
			'FILE_HANDLER_MISSING',
			$fieldName,
			400
		);
	}

	public static function preconditionFailed(): self
	{
		return new self('ETag mismatch — the resource has been modified.', 'PRECONDITION_FAILED', null, 412);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	public function getValidationErrors(): array
	{
		return $this->validationErrors;
	}

	public function toArray(bool $debug = false): array
	{
		$error = [
			'message' => $this->getMessage(),
			'extensions' => [
				'code' => $this->errorCode,
			],
		];

		if ($this->field !== null) {
			$error['extensions']['field'] = $this->field;
		}

		if (!empty($this->validationErrors)) {
			$error['extensions']['validationErrors'] = $this->validationErrors;
		}

		if ($debug && $this->getPrevious() !== null) {
			$error['extensions']['trace'] = $this->getPrevious()->getTraceAsString();
		}

		return ['errors' => [$error]];
	}
}
