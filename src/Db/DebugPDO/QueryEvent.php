<?php

namespace ON\Db\DebugPDO;

use League\Event\HasEventName;

class QueryEvent implements HasEventName
{
    public function __construct(
        private string $name, 
        private object $query,
        private string $type
    )
    {
    }

    public function eventName(): string
    {
        return $this->name;
    }

    public function getQuery(): object
    {
        return $this->query;
    }

    public function getType(): string
    {
        return $this->type;
    }
}