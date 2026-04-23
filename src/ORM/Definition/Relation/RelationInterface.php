<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Display\DisplayInterface;
use ON\ORM\Definition\Display\RawDisplay;
use ON\ORM\Definition\Interface\InterfaceInterface;

interface RelationInterface
{
	public function display(string $type = RawDisplay::class): DisplayInterface;

	public function getDisplay(): DisplayInterface;

	public function interface(string $className): InterfaceInterface;

	public function getInterface(): InterfaceInterface;

	public function name(string $name): self;

	public function getName(): string;

	public function collection(string $collection): self;

	public function getCollection(): string;

	public function nullable(bool $nullable): self;

	public function isNullable(): bool;

	public function cascade(bool $cascade): self;

	public function isCascade(): bool;

	public function load(string $load): self;

	public function getLoadStrategy(): string;

	public function innerKey(mixed $key): self;

	public function getInnerKey(): mixed;

	public function outerKey(mixed $key): self;

	public function getOuterKey(): mixed;

	public function loader(string $loader): self;

	public function getLoader(): string;

	public function where(array $where): self;

	public function getWhere(): array;

	public function orderBy(array $orderBy): self;

	public function getOrderBy(): array;

	/**
	 * Returns 'single' or 'many' — whether this relation resolves to one item or a collection.
	 */
	public function getCardinality(): string;

	/**
	 * Whether this is a junction/pivot relation (M2M) that uses a through table.
	 */
	public function isJunction(): bool;

	public function end(): CollectionInterface;
}
