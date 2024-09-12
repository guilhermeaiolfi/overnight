<?php
namespace ON\Db\Cycle\Contrain;

use Cycle\ORM\Select\ConstrainInterface;
use Cycle\ORM\Select\QueryBuilder;

class SortByWhenConstrain implements ConstrainInterface
{
    public function apply(QueryBuilder $query): void
    {
        $query->groupBy('id');
    }
}
