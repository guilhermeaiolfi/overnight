<?php

declare(strict_types=1);

namespace ON\Validation;

use ON\Data\Definition\Collection\CollectionInterface;
use Somnambulist\Components\Validation\ErrorBag;
use Somnambulist\Components\Validation\Factory as ValidationFactory;

final class CollectionValidator
{
	private ?ValidationFactory $factory = null;

	/**
	 * @param array<string, string> $defaultMessages
	 */
	public function __construct(
		private array $defaultMessages = [],
		private string $lang = 'en',
	) {
	}

	/**
	 * @throws ValidationFailedException
	 */
	public function validate(
		CollectionInterface $collection,
		array $input,
		bool $partial = false,
		bool $skipPrimaryKey = false,
	): void {
		$this->validateAvailable($collection, $input, [], $partial, $skipPrimaryKey);
	}

	/**
	 * Validate rules whose inputs are known. Returns false when a field or a
	 * rule referencing it must be checked again after its value is generated.
	 *
	 * @param list<string> $pendingFields
	 */
	public function validateAvailable(
		CollectionInterface $collection,
		array $input,
		array $pendingFields,
		bool $partial = false,
		bool $skipPrimaryKey = false,
	): bool {
		$rules = [];
		$messages = [];
		$complete = true;

		foreach ($collection->fields as $name => $field) {
			if ($skipPrimaryKey && $field->isPrimaryKey()) {
				continue;
			}

			$fieldRules = $field->getValidation();
			if ($fieldRules === null) {
				continue;
			}

			if (in_array($name, $pendingFields, true)) {
				$complete = false;

				continue;
			}
			if ($partial && ! array_key_exists($name, $input)) {
				continue;
			}
			if ($pendingFields !== [] && $this->referencesPendingField($fieldRules, $pendingFields)) {
				$complete = false;

				continue;
			}

			$rules[$name] = $fieldRules;

			$fieldMessages = $field->getValidationMessages();
			if ($fieldMessages !== []) {
				$messages = array_merge($messages, $this->expandFieldMessages($name, $fieldMessages));
			}
		}

		if ($rules === []) {
			return $complete;
		}

		$validation = $this->factory()->make($input, $rules);
		$validation->setLanguage($this->lang);

		if ($messages !== []) {
			$validation->messages()->add($this->lang, $messages);
		}

		$validation->validate();

		if ($validation->fails()) {
			throw ValidationFailedException::fromErrors($this->normalizeErrors($validation->errors()));
		}

		return $complete;
	}

	/** @param list<string> $pendingFields */
	private function referencesPendingField(string $rules, array $pendingFields): bool
	{
		foreach (explode('|', $rules) as $rule) {
			[, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
			if ($parameters !== null && array_intersect($pendingFields, str_getcsv($parameters, escape: '\\')) !== []) {
				return true;
			}
		}

		return false;
	}

	private function factory(): ValidationFactory
	{
		if ($this->factory !== null) {
			return $this->factory;
		}

		$this->factory = new ValidationFactory();

		if ($this->defaultMessages !== []) {
			$this->factory->messages()->add($this->lang, $this->defaultMessages);
		}

		return $this->factory;
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private function normalizeErrors(ErrorBag $errors): array
	{
		$normalized = [];

		foreach ($errors->toArray() as $field => $rules) {
			$normalized[$field] = array_map(
				static fn ($message) => (string) $message,
				array_values($rules)
			);
		}

		return $normalized;
	}

	/**
	 * @param array<string, string> $fieldMessages
	 * @return array<string, string>
	 */
	private function expandFieldMessages(string $fieldName, array $fieldMessages): array
	{
		$expanded = [];

		foreach ($fieldMessages as $key => $message) {
			if (str_contains($key, ':') || str_starts_with($key, 'rule.')) {
				$expanded[$key] = $message;

				continue;
			}

			if ($key === $fieldName) {
				$expanded[$key] = $message;

				continue;
			}

			$expanded[$fieldName . ':' . $key] = $message;
		}

		return $expanded;
	}
}
