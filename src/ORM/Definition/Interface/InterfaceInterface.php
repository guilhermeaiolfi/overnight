<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Interface;

interface InterfaceInterface
{
	public function setOptions(array $options): self;

	public function getOptions(): array;

	/** @return RelationInterface|FieldInterface */
	public function end(): mixed;
}
