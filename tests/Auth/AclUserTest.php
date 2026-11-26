<?php

declare(strict_types=1);

namespace Tests\ON\Auth;

use ON\Auth\User\AclUser;
use PHPUnit\Framework\TestCase;

final class AclUserTest extends TestCase
{
	public function testHasRoleMatchesEveryRoleByValueAndType(): void
	{
		$user = new AclUser(['roles' => ['editor', 10]]);

		$this->assertTrue($user->hasRole('editor'));
		$this->assertTrue($user->hasRole(10));
		$this->assertFalse($user->hasRole('10'));
	}

	public function testHasRoleRequiresAConfiguredList(): void
	{
		$this->assertFalse((new AclUser([]))->hasRole('editor'));
		$this->assertFalse((new AclUser(['roles' => 'editor']))->hasRole('editor'));
	}
}
