<?php

declare(strict_types=1);

namespace ON\Image;

use Psr\Http\Message\ResponseInterface;

interface PlaceholderImageInterface
{
	public function getUri(ImageManager $imageManager, string $token, ImageRequest $imageRequest): string;

	public function getResponse(ImageManager $imageManager, string $token, ImageRequest $imageRequest): ResponseInterface;
}
