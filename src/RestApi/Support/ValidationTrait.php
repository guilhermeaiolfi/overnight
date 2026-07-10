<?php

declare(strict_types=1);

namespace ON\RestApi\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Error\RestApiError;
use ON\Validation\CollectionValidator;
use ON\Validation\ValidationFailedException;

trait ValidationTrait
{
	protected ?CollectionValidator $validator = null;

	protected function validate(CollectionInterface $collection, array $input, bool $isPartial = false): void
	{
		try {
			$this->validator()->validate($collection, $input, $isPartial);
		} catch (ValidationFailedException $e) {
			throw RestApiError::validationFailed($e->getErrors());
		}
	}

	private function validator(): CollectionValidator
	{
		return $this->validator ??= new CollectionValidator(
			$this->config->get('validationMessages', []),
			$this->config->get('validationLang', 'en'),
		);
	}
}
