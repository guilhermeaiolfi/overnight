<?php

declare(strict_types=1);

namespace ON\RestApi\Error;

use RuntimeException;
use Throwable;

class RestApiError extends RuntimeException
{
	protected array $validationErrors = [];
	protected array $extensions = [];

	public function __construct(
		string $message,
		protected string $errorCode = 'INTERNAL_ERROR',
		protected ?string $field = null,
		protected int $httpStatus = 500,
		?Throwable $previous = null,
		array $extensions = [],
	) {
		parent::__construct($message, 0, $previous);
		$this->extensions = $extensions;
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

	/**
	 * Related payload identity does not exist in the represented collection.
	 *
	 * @param array<string, mixed>|string|int|float|bool $identity
	 */
	public static function relatedNotFound(
		string $collection,
		array|string|int|float|bool $identity,
		string $relation,
		string $path,
		string $operation = 'reference',
	): self {
		$pathKey = $path !== '' ? $path : '_root';
		$message = sprintf(
			"Related item in '%s' was not found for relation '%s' (%s).",
			$collection,
			$relation,
			$operation,
		);

		$error = new self($message, 'RELATED_NOT_FOUND', $pathKey, 404, null, [
			'collection' => $collection,
			'identity' => $identity,
			'relation' => $relation,
			'path' => $pathKey,
			'operation' => $operation,
		]);
		$error->validationErrors = [
			$pathKey => [$message],
		];

		return $error;
	}

	/**
	 * Related identity exists but is not a current member of the mutated relation.
	 *
	 * @param array<string, mixed>|string|int|float|bool $identity
	 */
	public static function relationTargetOutOfScope(
		string $collection,
		array|string|int|float|bool $identity,
		string $relation,
		string $path,
		string $operation = 'update',
	): self {
		$pathKey = $path !== '' ? $path : '_root';
		$message = sprintf(
			"Related item in '%s' is not part of relation '%s' (%s).",
			$collection,
			$relation,
			$operation,
		);

		$error = new self($message, 'INVALID_RELATION_TARGET', $pathKey, 400, null, [
			'collection' => $collection,
			'identity' => $identity,
			'relation' => $relation,
			'path' => $pathKey,
			'operation' => $operation,
			'reason' => 'out_of_scope',
		]);
		$error->validationErrors = [
			$pathKey => [$message],
		];

		return $error;
	}

	/**
	 * The same represented identity appears more than once in one relation payload.
	 *
	 * @param array<string, mixed>|string|int|float|bool $identity
	 */
	public static function duplicateRelatedIdentity(
		string $collection,
		array|string|int|float|bool $identity,
		string $relation,
		string $path,
	): self {
		$pathKey = $path !== '' ? $path : '_root';
		$message = sprintf(
			"Duplicate related identity in relation '%s'.",
			$relation,
		);

		$error = new self($message, 'DUPLICATE_RELATED_IDENTITY', $pathKey, 400, null, [
			'collection' => $collection,
			'identity' => $identity,
			'relation' => $relation,
			'path' => $pathKey,
		]);
		$error->validationErrors = [
			$pathKey => [$message],
		];

		return $error;
	}

	public static function identityMutationNotAllowed(string $path = ''): self
	{
		$pathKey = $path !== '' ? $path : '_root';
		$message = 'Primary key fields cannot be changed through mutation hooks.';

		$error = new self($message, 'IDENTITY_MUTATION_NOT_ALLOWED', $pathKey, 400, null, [
			'path' => $pathKey,
		]);
		$error->validationErrors = [
			$pathKey => [$message],
		];

		return $error;
	}

	public static function mutationPrevented(string $message = 'Mutation prevented.'): self
	{
		return new self($message, 'MUTATION_PREVENTED', null, 400);
	}

	public static function collectionNotFound(string $collection): self
	{
		return new self("Collection '{$collection}' not found.", 'COLLECTION_NOT_FOUND', null, 404);
	}

	public static function invalidJson(): self
	{
		return new self('Invalid JSON in request body.', 'INVALID_JSON', null, 400);
	}

	public static function invalidField(string $field): self
	{
		return new self("Invalid field '{$field}'.", 'INVALID_FIELD', $field, 400);
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

	public static function forbidden(string $message = 'Forbidden.', array $extensions = []): self
	{
		return new self($message, 'FORBIDDEN', null, 403, null, $extensions);
	}

	public static function unauthenticated(string $message = 'Authentication required.', array $extensions = []): self
	{
		return new self($message, 'UNAUTHENTICATED', null, 401, null, $extensions);
	}

	public static function internal(Throwable $previous, bool $debug = false): self
	{
		$message = trim($previous->getMessage());
		if ($message === '') {
			$message = 'Internal server error.';
		}

		return new self(
			$debug ? $message : $message,
			'INTERNAL_ERROR',
			null,
			500,
			$previous,
			[
				'exception' => $previous::class,
			],
		);
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

		if ($this->extensions !== []) {
			$error['extensions'] = array_merge($error['extensions'], $this->extensions);
		}

		if (! empty($this->validationErrors)) {
			$error['extensions']['validationErrors'] = $this->validationErrors;
		}

		if ($debug && $this->getPrevious() !== null) {
			$error['extensions']['trace'] = $this->getPrevious()->getTraceAsString();
		}

		return ['errors' => [$error]];
	}
}
