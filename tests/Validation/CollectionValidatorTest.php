<?php

declare(strict_types=1);

namespace Tests\ON\Validation;

use ON\Data\Definition\Registry;
use ON\Validation\CollectionValidator;
use ON\Validation\ValidationFailedException;
use PHPUnit\Framework\TestCase;

final class CollectionValidatorTest extends TestCase
{
	public function testFieldRuleShorthandOverridesAppDefault(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3', [
					'min' => 'Name must be at least :min characters.',
				])
				->end()
			->end();

		$validator = new CollectionValidator([
			'rule.min' => 'Generic minimum is :min.',
		]);

		try {
			$validator->validate($registry->getCollection('user'), ['name' => 'Jo']);
			$this->fail('Expected validation to fail.');
		} catch (ValidationFailedException $e) {
			$this->assertSame(
				['name' => ['Name must be at least 3 characters.']],
				$e->getErrors()
			);
		}
	}

	public function testAppDefaultOverridesLibraryMessage(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required')
				->end()
			->end();

		$validator = new CollectionValidator([
			'rule.required' => 'The :attribute field is required.',
		]);

		try {
			$validator->validate($registry->getCollection('user'), []);
			$this->fail('Expected validation to fail.');
		} catch (ValidationFailedException $e) {
			$this->assertSame(
				['email' => ['The email field is required.']],
				$e->getErrors()
			);
		}
	}

	public function testFieldLevelFallbackMessageIsUsed(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required|email', [
					'email' => 'Please provide a valid email address.',
				])
				->end()
			->end();

		$validator = new CollectionValidator();

		try {
			$validator->validate($registry->getCollection('user'), ['email' => 'not-an-email']);
			$this->fail('Expected validation to fail.');
		} catch (ValidationFailedException $e) {
			$this->assertSame(
				['email' => ['Please provide a valid email address.']],
				$e->getErrors()
			);
		}
	}

	public function testPartialValidationSkipsAbsentFields(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->field('bio', 'string')->type('string')->nullable(true)
				->validation('required')
				->end()
			->end();

		$validator = new CollectionValidator();

		$validator->validate($registry->getCollection('user'), ['name' => 'Jane'], partial: true);
		$this->addToAssertionCount(1);
	}

	public function testSkipPrimaryKeyMatchesGraphqlBehavior(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)
				->validation('required')
				->end()
			->field('name', 'string')->type('string')->nullable(true)
				->validation('required|min:3')
				->end()
			->end();

		$validator = new CollectionValidator();

		try {
			$validator->validate(
				$registry->getCollection('user'),
				['name' => 'Jo'],
				skipPrimaryKey: true,
			);
			$this->fail('Expected validation to fail.');
		} catch (ValidationFailedException $e) {
			$this->assertArrayHasKey('name', $e->getErrors());
			$this->assertArrayNotHasKey('id', $e->getErrors());
		}
	}

	public function testFullMessageKeysArePassedThrough(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->field('email', 'string')->type('string')->nullable(true)
				->validation('required', [
					'email:required' => 'Email is mandatory.',
					'rule.required' => 'Should not be used for this field.',
				])
				->end()
			->end();

		$validator = new CollectionValidator([
			'rule.required' => 'Generic required message.',
		]);

		try {
			$validator->validate($registry->getCollection('user'), []);
			$this->fail('Expected validation to fail.');
		} catch (ValidationFailedException $e) {
			$this->assertSame(
				['email' => ['Email is mandatory.']],
				$e->getErrors()
			);
		}
	}
}
