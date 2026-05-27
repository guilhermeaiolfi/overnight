<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

class FirstOfManyRelation extends HasManyRelation
{
	public function getCardinality(): string
	{
		return 'single';
	}
}
