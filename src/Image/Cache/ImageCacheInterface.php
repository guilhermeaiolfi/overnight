<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Intervention\Image\Interfaces\ModifierInterface;
use ON\FS\FilePathInterface;
use ON\FS\PublicAssetInterface;

interface ImageCacheInterface
{
	public function get(string $token, callable|ModifierInterface $template, string|FilePathInterface $path): string;

	public function publicAsset(string|FilePathInterface $path, string $token): PublicAssetInterface;

	public function token(string $path): string;
}
