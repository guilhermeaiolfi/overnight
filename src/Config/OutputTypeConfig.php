<?php
namespace ON\Config;

use ON\View\Plates\PlatesRenderer;

class OutputTypeConfig {

    protected ?OutputTypeConfig $default = null;

    protected array $types = [];
    public function __construct(
        public ?array $renderers = [],
        public ?array $layouts = []
    )
    {

    }
}