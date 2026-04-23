<?php

declare(strict_types=1);

namespace ON\Auth;

class Result
{
	/**
	 * General Failure
	 */
	public const FAILURE = 0;

	/**
	 * Failure due to identity not being found.
	 */
	public const FAILURE_IDENTITY_NOT_FOUND = -1;

	/**
	 * Failure due to identity being ambiguous.
	 */
	public const FAILURE_IDENTITY_AMBIGUOUS = -2;

	/**
	 * Failure due to invalid credential being supplied.
	 */
	public const FAILURE_CREDENTIAL_INVALID = -3;

	/**
	 * Failure due to uncategorized reasons.
	 */
	public const FAILURE_UNCATEGORIZED = -4;

	/**
	 * Authentication success.
	 */
	public const SUCCESS = 1;

	public function __construct(
		/**
		 * Authentication result code
		 */
		protected int $code,
		/**
		 * The identity used in the authentication attempt
		 */
		protected mixed $identity,
		/**
		* An array of string reasons why the authentication attempt was unsuccessful
		*
		* If authentication was successful, this should be an empty array.
		*/
		protected array $messages = []
	) {
	}

	/**
	 * Returns whether the result represents a successful authentication attempt
	 */
	public function isValid(): bool
	{
		return $this->code > 0;
	}

	/**
	 * getCode() - Get the result code for this authentication attempt
	 */
	public function getCode(): int
	{
		return $this->code;
	}

	/**
	 * Returns the identity used in the authentication attempt
	 */
	public function getIdentity(): mixed
	{
		return $this->identity;
	}

	/**
	 * Returns an array of string reasons why the authentication attempt was unsuccessful
	 *
	 * If authentication was successful, this method returns an empty array.
	 */
	public function getMessages(): array
	{
		return $this->messages;
	}
}
