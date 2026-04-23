<?php

declare(strict_types=1);

namespace Tests\ON\Fixtures;

use ON\ORM\Definition\Registry;

class UserPartsRegistry extends Registry
{
	public function __construct()
	{
		$this->collection("users")
			->field("id")->end()
			->field("name")->end()
			->relation("parts")
				->collection('parts')
			->end()
			->relation("user")
				->collection("users")
			->end()
			->relation("foo")
				->collection("foos")
			->end()
		->end()
		->collection("foos")
			->field("id")->end()
			->field("name")->end()
			->relation("bar")
				->collection("bars")
			->end()
		->end()
		->collection("bars")
			->field("id")->end()
			->field("name")->end()
			->relation('user')
				->collection('users')
			->end()
		->end()
		->collection("parts")
			->field("id")->end()
			->field("name")->end()
			->relation('user')
				->collection('users')
			->end()
		->end();
	}
}
