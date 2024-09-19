<?php

namespace ON\Extension;

abstract class AbstractExtension implements ExtensionInterface
{
    public const TYPE_MODULE = 1;
    public const TYPE_EXTENSION = 2;
    public const TYPE_AGGREGATION = 3;

    protected int $type = self::TYPE_MODULE;

    protected array $pendingTags = [];

    protected bool $__ready = false;

    public function getType(): int 
    {
        return $this->type;
    }

    public function isReady(): bool
    {
        return $this->__ready;
    }

    public function setReady(bool $ready): void
    {
        $this->__ready = $ready;
    }

    public function ready()
    {
        return;
    }

    public function setup(int $counter): bool
    {
        return true;
    }

    public function getPendingTags(): array
    {
        return $this->pendingTags;
    }

    public function removePendingTag(mixed $tag): void
    {
        $key = array_search($tag, $this->pendingTags);
        if ($key !== false) {
            unset($this->pendingTags[$key]);
        }        
    }

    public function hasPendingTag(mixed $tag): bool
    {
        return in_array($tag, $this->pendingTags);
    }
}
