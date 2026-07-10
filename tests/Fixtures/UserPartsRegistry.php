<?php

declare(strict_types=1);

namespace Tests\ON\Fixtures;

use ON\Data\Definition\Registry;

final class UserPartsRegistry
{
	public static function create(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id')->end()
			->field('name')->end()
			->relation('parts')
				->collection('parts')
			->end()
			->relation('user')
				->collection('users')
			->end()
			->relation('foo')
				->collection('foos')
			->end()
		->end()
		->collection('foos')
			->primaryKey('id')
			->field('id')->end()
			->field('name')->end()
			->relation('bar')
				->collection('bars')
			->end()
		->end()
		->collection('bars')
			->primaryKey('id')
			->field('id')->end()
			->field('name')->end()
			->relation('user')
				->collection('users')
			->end()
		->end()
		->collection('parts')
			->primaryKey('id')
			->field('id')->end()
			->field('name')->end()
			->relation('user')
				->collection('users')
			->end()
		->end();

		return $registry;
	}
}
