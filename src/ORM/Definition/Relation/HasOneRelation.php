<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Select\Loader\BelongsToLoader;

class HasOneRelation extends AbstractRelation
{
	public bool $exclusive = false;

	public function exclusive(bool $exclusive): self
	{
		$this->exclusive = $exclusive;

		return $this;
	}

	public function isExclusive(): bool
	{
		return $this->exclusive;
	}

	public function end(): CollectionInterface
	{
		$this->generateField();

		return parent::end();
	}

	// creates the field into the parent collection
	public function generateField(): FieldInterface
	{

		$parentCollection = $this->parent;

		$inner_key = $this->getInnerKey();
		$outer_key = $this->getOuterKey();

		$registry = $parentCollection->getRegistry();

		$targetCollection = $registry->getCollection($this->getCollection());
		$type = $targetCollection->fields->get($outer_key)->getType();

		$field = $parentCollection->field($inner_key);
		$field
			->setGeneratedFromRelation($parentCollection->getName())
			->type($type);

		return $field;
	}

	public function getLoader(): string
	{
		if (isset($this->loader)) {
			return $this->loader;
		}
		if ($this->isNullable()) {
			return BelongsToLoader::class;
		}

		return BelongsToLoader::class;
	}
}
