<?php

declare(strict_types=1);

namespace ON\Image\Cache;

use Intervention\Image\Interfaces\ModifierInterface;
use ON\FS\FilePathInterface;
use ON\FS\PublicAssetInterface;

interface ImageCacheInterface
{
	public function create(string $token, callable|ModifierInterface $template, string|FilePathInterface $sourcePath): PublicAssetInterface;

	public function get(string|FilePathInterface $sourcePath, string $token): PublicAssetInterface;

	public function extractToken(string $path): string;
}
