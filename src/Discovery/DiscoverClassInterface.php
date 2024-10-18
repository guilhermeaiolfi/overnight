<?php

namespace ON\Discovery;

interface DiscoverClassInterface {
    public function cachedTimestamp(): float;
    public function updateClasses($definitions): bool;
}