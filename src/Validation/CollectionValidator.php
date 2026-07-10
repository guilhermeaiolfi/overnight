<?php

declare(strict_types=1);

namespace ON\Validation;

use ON\Data\Definition\Collection\CollectionInterface;
use Somnambulist\Components\Validation\ErrorBag;
use Somnambulist\Components\Validation\Factory as ValidationFactory;

final class CollectionValidator
{
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
		$rules = [];
		$messages = [];

		foreach ($collection->fields as $name => $field) {
			if ($skipPrimaryKey && $field->isPrimaryKey()) {
				continue;
			}

			$fieldRules = $field->getValidation();
			if ($fieldRules === null) {
				continue;
			}

			if ($partial && ! array_key_exists($name, $input)) {
				continue;
			}

			$rules[$name] = $fieldRules;

			$fieldMessages = $field->getValidationMessages();
			if ($fieldMessages !== []) {
				$messages = array_merge($messages, $this->expandFieldMessages($name, $fieldMessages));
			}
		}

		if ($rules === []) {
			return;
		}

		$factory = new ValidationFactory();
		$validation = $factory->make($input, $rules);
		$validation->setLanguage($this->lang);

		if ($this->defaultMessages !== []) {
			$validation->messages()->add($this->lang, $this->defaultMessages);
		}

		if ($messages !== []) {
			$validation->messages()->add($this->lang, $messages);
		}

		$validation->validate();

		if ($validation->fails()) {
			throw ValidationFailedException::fromErrors($this->normalizeErrors($validation->errors()));
		}
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
