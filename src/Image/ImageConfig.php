<?php

namespace ON\Image;

use On\Config\Config;

class ImageConfig extends Config {
    public static function getDefaults(): array
    {
        return [
            "basePath" => "i/"
        ];
    }
}