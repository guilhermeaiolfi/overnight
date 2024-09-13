<?php

namespace ON\Config;

interface ConfigInterface
{
    public function get($string): mixed;
    public function all();
}
