<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Schema;

use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Definition\Relation\RelationInterface;

interface SchemaInterface
{
	public function end(): FieldInterface|RelationInterface;
}
