<?php

namespace ON\Config;

class LayoutConfig {
    public function __construct (
        public ?string $renderer = null,
        public ?string $template = null
    )
    {

    }
}