<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Collection;

use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Registry;
use ON\ORM\Definition\Relation\BelongsToRelation;
use ON\ORM\Definition\Relation\HasManyRelation;
use ON\ORM\Definition\Relation\HasOneRelation;
use ON\ORM\Definition\Relation\RelationInterface;

interface CollectionInterface
{
	public function entity(string $entity): self;

	public function getEntity(): string;

	public function table(string $table): self;

	public function getTable(): string;

	public function scope(string $scope): self;

	public function getScope(): ?string;

	public function source(string $source): self;

	public function getSource(): ?string;

	public function database(string $database): self;

	public function getDatabase(): string;

	public function repository(?string $repository): self;

	public function getRepository(): ?string;

	public function mapper(string $mapper): self;

	public function getMapper(): string;

	public function name(string $name): self;

	public function getName(): string;

	public function hidden(bool $hidden): self;

	public function isHidden(): bool;

	public function field(string $name, ?string $type = null): FieldInterface;

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 * */
	public function relation(string $name, string $type = HasOneRelation::class): RelationInterface;

	public function hasMany(string $name, string $targetCollection): HasManyRelation;

	public function hasOne(string $name, string $targetCollection): HasOneRelation;

	public function belongsTo(string $name, string $targetCollection): BelongsToRelation;

	/** @return FieldInterface[]|FieldInterface */
	public function getPrimaryKey(): mixed;

	public function note(string $note): self;

	public function getNote(): ?string;

	public function end(): Registry;

	public function getRegistry(): Registry;

	public function parentCollection(string $parentCollection): self;

	public function getParentCollection(): ?string;

	public function setFileDefinitionLocation(?string $file = null): void;

	public function getFileDefinitionLocation(): ?string;
}
