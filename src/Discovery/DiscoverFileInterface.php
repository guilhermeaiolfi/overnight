<?php

namespace ON\Discovery;

interface DiscoverFileInterface {
    public function updateFiles($files): bool;
}