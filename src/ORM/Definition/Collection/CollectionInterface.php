<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Collection;

use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;

interface CollectionInterface
{
	public function entity(string $entity): self;

	public function getEntity(): string;

	public function scope(string $scope): self;

	public function getScope(): string;

	public function repository(string $repository): self;

	public function getRepository(): string;

	public function mapper(string $mapper): self;

	public function getMapper(): string;

	public function name(string $name): self;

	public function getName(): string;

	public function hidden(bool $hidden): self;

	public function isHidden(): bool;

	public function field(string $name): FieldInterface;

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 * */
	public function relation(string $name, string $type = HasOneRelation::class): RelationInterface;

	/** @return FieldInterface[]|FieldInterface */
	public function getPrimaryKey(): mixed;

	public function note(string $note): self;

	public function getNote(): ?string;

	public function end(): Registry;

	public function getRegistry(): Registry;
}
