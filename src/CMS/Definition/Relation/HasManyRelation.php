<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

class HasManyRelation extends AbstractRelation
{
	public array $where;

	public function where(array $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function getWhere(): array
	{
		return $this->where;
	}
}
