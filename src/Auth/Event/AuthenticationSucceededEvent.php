<?php

declare(strict_types=1);

namespace ON\Auth\Event;

use ON\Auth\AuthenticatorInterface;
use ON\Auth\Result;
use ON\Event\HasEventNameInterface;

class AuthenticationSucceededEvent implements HasEventNameInterface
{
	public function __construct(
		protected Result $result,
		protected mixed $identity,
		protected ?AuthenticatorInterface $authenticator = null
	) {
	}

	public function eventName(): string
	{
		return 'auth.login';
	}

	public function getResult(): Result
	{
		return $this->result;
	}

	public function getIdentity(): mixed
	{
		return $this->identity;
	}

	public function getAuthenticator(): ?AuthenticatorInterface
	{
		return $this->authenticator;
	}
}
