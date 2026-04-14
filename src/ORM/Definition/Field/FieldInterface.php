<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Field;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Display\DisplayInterface;
use ON\ORM\Definition\Display\RawDisplay;
use ON\ORM\Definition\Interface\InterfaceInterface;

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

	public function alias(string $alias): self;

	public function getAlias(): string;

	public function setGeneratedFromRelation(?string $name): self;

	public function getGeneratedFromRelation(): ?string;

	public function type(string $type): self;

	public function getType(): string;

	public function column(string $column): self;

	public function getColumn(): string;

	public function primaryKey(bool $pk): self;

	public function isPrimaryKey(): bool;

	public function filterable(bool $filterable = true): self;

	public function isFilterable(): bool;

	public function required(bool $required): self;

	public function isRequired(): bool;

	public function hasTypecast(): bool;

	/**
	 * @param callable-array|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self;

	/**
	 * Set validation rules (pipe-delimited string, e.g. 'required|email|max:255').
	 */
	public function validation(?string $rules): self;

	public function getValidation(): ?string;

	public function end(): CollectionInterface;
}
