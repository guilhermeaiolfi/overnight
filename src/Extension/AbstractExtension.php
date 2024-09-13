<?php

namespace ON\Extension;

abstract class AbstractExtension implements ExtensionInterface
{
    public const TYPE_MODULE = 1;
    public const TYPE_EXTENSION = 2;
    public const TYPE_AGGREGATION = 3;

    protected int $type = self::TYPE_MODULE;

    public function getType(): int 
    {
        return $this->type;
    }    
}
