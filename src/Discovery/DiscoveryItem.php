<?php

declare(strict_types=1);

namespace ON\Discovery;

class DiscoveryItem
{
    public function __construct(
        private mixed $value,
        private ?DiscoveryLocation $location = null,
        private array $tags = [],
        private bool $fresh = true,
        private ?string $file = null,
        private ?string $className = null,
        private array $metadata = []
    ) {}

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    public function getLocation(): DiscoveryLocation
    {
        return $this->location;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setFresh(bool $fresh): self
    {
        $this->fresh = $fresh;
        return $this;
    }

    public function isFresh(): bool
    {
        return $this->fresh;
    }

    public function setFile(string $file): void
    {
        $this->file = $file;
    }

    public function getFile(): ?string {
        return $this->file;
    }
    
    public function setClassName(string $className): void
    {
        $this->className = $className;
    }

    public function getClassName(): ?string {
        return $this->className;
    }

    public function setMetadata(string $name, mixed $value): self
    {
        $this->metadata[$name] = $value;
        return $this;
    }

    public function getMetadata(string $name): mixed
    {
        return $this->metadata[$name];
    }

    public function __serialize(): array
    {
        return [
            $this->value,
            $this->location,
            $this->tags,
            $this->file,
            $this->className,
            $this->metadata,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->value = $data[0];
        $this->location = $data[1];
        $this->tags = $data[2];
        $this->file = $data[3];
        $this->className = $data[4];
        $this->metadata = $data[5] ?? [];
        $this->fresh = false;
    }

}
