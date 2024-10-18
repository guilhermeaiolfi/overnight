<?php

namespace ON\Extension;

abstract class AbstractExtension implements ExtensionInterface
{
    public const TYPE_MODULE = 1;
    public const TYPE_EXTENSION = 2;
    public const TYPE_AGGREGATION = 3;

    protected int $type = self::TYPE_MODULE;

    protected array $pendingTasks = [];

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

    public function getPendingTasks(): array
    {
        return $this->pendingTasks;
    }

    public function removePendingTask(mixed $task): void
    {
        $key = array_search($task, $this->pendingTasks);
        if ($key !== false) {
            unset($this->pendingTasks[$key]);
        }
    }

    public function hasPendingTask(mixed $task): bool
    {
        return in_array($task, $this->pendingTasks);
    }

    public function hasPendingTasks(): bool
    {
        return count($this->pendingTasks) > 0;
    }
}
