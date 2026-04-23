<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

class M2MThrough
{
	protected string $collection;
	protected mixed $inner_key;
	protected mixed $outer_key;
	protected array $where;

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

	public function innerKey(mixed $key): self
	{
		$this->inner_key = $key;

		return $this;
	}

	public function getInnerKey(): mixed
	{
		return $this->inner_key;
	}

	public function outerKey(mixed $key): self
	{
		$this->outer_key = $key;

		return $this;
	}

	public function getOuterKey(): mixed
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
