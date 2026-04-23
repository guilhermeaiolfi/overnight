<?php

declare(strict_types=1);

namespace ON\ORM\Definition\Relation;

use ON\ORM\Definition\Collection\Collection;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Field\Field;
use ON\ORM\Definition\Field\FieldInterface;
use ON\ORM\Select\Loader\BelongsToLoader;

// TODO: I need to really think about it to make sure that's the right behavior.
class BelongsToRelation extends HasOneRelation
{
	protected bool $nullable = true;
}
