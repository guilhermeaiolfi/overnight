<?php

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface {

    public static function install(Application $app): mixed;
}