<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Display;

use ON\CMS\Definition\Field;
use ON\CMS\Definition\Relation;

interface DisplayInterface
{
	public function type(string $type): self;

	public function getType(): string;

	public function setOptions(array $options): self;

	public function getOptions(): array;

	/** @return Relation|Field */
	public function end(): mixed;
}
