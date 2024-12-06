<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

use ON\CMS\Definition\Collection\CollectionInterface;
use ON\CMS\Definition\Display\DisplayInterface;
use ON\CMS\Definition\Display\RawDisplay;
use ON\CMS\Definition\Interface\InterfaceInterface;

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

	public function innerKey(string $key): self;

	public function getInnerKey(): string;

	public function outerKey(string $key): self;

	public function getOuterKey(): string;

	public function end(): CollectionInterface;
}
