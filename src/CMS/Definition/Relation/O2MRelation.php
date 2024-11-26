<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

class O2MRelation extends Relation
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
