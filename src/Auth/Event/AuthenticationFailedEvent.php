<?php

declare(strict_types=1);

namespace ON\Auth\Event;

use ON\Auth\AuthenticatorInterface;
use ON\Auth\Result;
use ON\Event\HasEventNameInterface;

class AuthenticationFailedEvent implements HasEventNameInterface
{
	public function __construct(
		protected Result $result,
		protected ?AuthenticatorInterface $authenticator = null
	) {
	}

	public function eventName(): string
	{
		return 'auth.failure';
	}

	public function getResult(): Result
	{
		return $this->result;
	}

	public function getAuthenticator(): ?AuthenticatorInterface
	{
		return $this->authenticator;
	}
}
