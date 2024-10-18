<?php

namespace ON\Image\Cache;

interface ImageCacheInterface {
    public function get($url, $template, $path);
    public function filename($path, $token);
    public function token($path);
}