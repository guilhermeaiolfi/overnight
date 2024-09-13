<?php

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface {

    public static function install(Application $app, ?array $options = []): mixed;
    public function getType(): int;
}