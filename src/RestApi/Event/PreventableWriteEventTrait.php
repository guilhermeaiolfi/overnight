<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

/**
 * Preventable write before-events. preventDefault() aborts flush for the request.
 */
trait PreventableWriteEventTrait
{
	private bool $defaultPrevented = false;

	/** @var array<string, mixed>|null */
	private ?array $preventResult = null;

	/**
	 * @param array<string, mixed>|null $result Optional alternate result for root writes.
	 */
	public function preventDefault(?array $result = null): void
	{
		$this->defaultPrevented = true;
		$this->preventResult = $result;
	}

	public function isDefaultPrevented(): bool
	{
		return $this->defaultPrevented;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getPreventResult(): ?array
	{
		return $this->preventResult;
	}
}
