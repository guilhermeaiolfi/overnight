<?php

namespace ON\Extension;

use Exception;
use ON\Application;

class ConfigExtension implements ExtensionInterface
{
 
    protected $config;
    public function __construct(
        protected Application $app
    ) {
        $this->config = require 'config/config.php';
    }

    public static function install(Application $app): mixed {
        $extension = new self($app);
        return $extension;
    }

    public function getConfig() {
        return $this->config;
    }
}
