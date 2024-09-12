<?php

namespace ON\Event;

use League\Event\HasEventName;

class NamedEvent implements HasEventName
{
    public function __construct(
        private string $name, 
        private ?object $subject = null
    )
    {
    }

    public function eventName(): string
    {
        return $this->name;
    }

    public function getSubject(): object
    {
        return $this->subject;
    }
}