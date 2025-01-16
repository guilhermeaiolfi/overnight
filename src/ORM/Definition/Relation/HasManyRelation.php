<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Select\Loader\HasManyLoader;

class HasManyRelation extends AbstractRelation
{
	public array $where;

	protected ?string $loader = HasManyLoader::class;

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
