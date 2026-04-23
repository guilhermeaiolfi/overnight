<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Display;

use ON\ORM\Definition\Field;
use ON\ORM\Definition\Relation;

interface DisplayInterface
{
	public function type(string $type): self;

	public function getType(): string;

	public function setOptions(array $options): self;

	public function getOptions(): array;

	/** @return Relation|Field */
	public function end(): mixed;
}
