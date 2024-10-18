<?php

namespace ON\Config;

class RouterConfig extends Config {
    public static function getDefaults(): array
    {
        return [
            "cache_enabled"    => !$_ENV["APP_DEBUG"], // true|false
            "cache_file"       => 'var/cache/router.php.cache', // optional
        ];
    }
}