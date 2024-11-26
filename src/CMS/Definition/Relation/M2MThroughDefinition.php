<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

class M2MThroughDefinition
{
	public string $collection;
	public string $inner_key;
	public string $outer_key;
	public array $where;

	public function __construct(
		protected M2MRelation $m2m
	) {

	}

	public function collection(string $collection): self
	{
		$this->collection = $collection;

		return $this;
	}

	public function getCollection(): string
	{
		return $this->collection;
	}

	public function innerKey(string $key): self
	{
		$this->inner_key = $key;

		return $this;
	}

	public function getInnerKey(): string
	{
		return $this->inner_key;
	}

	public function outerKey(string $key): self
	{
		$this->outer_key = $key;

		return $this;
	}

	public function getOuterKey(): string
	{
		return $this->outer_key;
	}

	public function where(array $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function getWhere(): array
	{
		return $this->where;
	}

	public function end(): M2MRelation
	{
		return $this->m2m;
	}
}
