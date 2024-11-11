<?php

declare(strict_types=1);

namespace ON\Auth;

use Laminas\Authentication\AuthenticationService as LaminasAuthenticationService;

class AuthenticationService extends LaminasAuthenticationService implements AuthenticationServiceInterface
{
	/*
	*  Just alias to ->clearIdentity();
	*/
	public function logout(): void
	{
		$this->getStorage()->clear();
	}
}
