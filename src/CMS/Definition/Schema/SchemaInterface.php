<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Schema;

use ON\CMS\Definition\Field\FieldInterface;
use ON\CMS\Definition\Relation\RelationInterface;

interface SchemaInterface
{
	public function end(): FieldInterface|RelationInterface;
}
