<?php

declare(strict_types=1);

namespace ON\Auth\User;

class AclUser extends StandardUser
{
	public function isAdmin()
	{
		return $this->hasRole("admin");
	}

	public function hasRole($role)
	{
		return is_array($this->data["roles"]) ?
			in_array($role, $this->data["roles"]) : false;
	}

	public function getRole()
	{
		return $this->data["role"];
	}
}
