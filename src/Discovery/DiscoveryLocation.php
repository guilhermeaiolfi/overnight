<?php

declare(strict_types=1);

namespace ON\Discovery;

class DiscoveryLocation {
    public function __construct (
        public string $name,
        public array $pattern,
        public string $strategy
    ) {

    }
}