<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Select\Loader\HasManyLoader;

class HasManyRelation extends AbstractRelation
{
	protected ?string $loader = HasManyLoader::class;

	public function getCardinality(): string
	{
		return 'many';
	}
}
