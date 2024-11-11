<?php

declare(strict_types=1);

namespace ON\Auth;

interface AuthenticationServiceInterface extends \Laminas\Authentication\AuthenticationServiceInterface
{
    public function logout(): void;
}
