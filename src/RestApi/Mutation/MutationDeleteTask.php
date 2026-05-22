<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

final class MutationDeleteTask implements MutationDeleteTaskInterface
{
	/** @var callable(): bool */
	private $result;

	/**
	 * @param callable(): bool $result
	 */
	public function __construct(callable $result)
	{
		$this->result = $result;
	}

	public function getResult(): bool
	{
		return ($this->result)();
	}
}
