<?php

namespace ON\Discovery;

interface DiscoverInterface {
    public function cachedTimestamp(): float;
    public function save(): bool;
    public function recover(): bool;
    public function process(): bool;
}