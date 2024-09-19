<?php

namespace ON\Extension;

use ON\Application;

interface ExtensionInterface {

    public static function install(Application $app, ?array $options = []): mixed;
    public function getType(): int;
    public function ready();
    public function setup(int $counter): bool;
    public function getPendingTags(): array;
    public function removePendingTag(mixed $tag): void;
    public function hasPendingTag(mixed $tag): bool;
}