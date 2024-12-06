<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Field;

use ON\CMS\Definition\Collection\CollectionInterface;
use ON\CMS\Definition\Display\DisplayInterface;
use ON\CMS\Definition\Display\RawDisplay;
use ON\CMS\Definition\Interface\InterfaceInterface;

interface FieldInterface
{
	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 */
	public function display(string $type = RawDisplay::class): DisplayInterface;

	public function getDisplay(): DisplayInterface;

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @return T
	 */
	public function interface(string $className): InterfaceInterface;

	public function getInterface(): InterfaceInterface;

	public function name(string $name): self;

	public function getName(): string;

	public function type(string $type): self;

	public function getType(): string;

	public function column(string $column): self;

	public function getColumn(): string;

	public function primaryKey(bool $pk): self;

	public function isPrimaryKey(): bool;

	public function required(bool $required): self;

	public function isRequired(): bool;

	public function hasTypecast(): bool;

	/**
	 * @param callable-array|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self;

	public function end(): CollectionInterface;
}
