<?php

declare(strict_types=1);

namespace ON\Image;

use ON\Image\Cache\ImageCacheInterface;
use Psr\Http\Message\ResponseInterface;

final class UserFilePlaceholderImage implements PlaceholderImageInterface
{
	public function __construct(
		private ImageConfig $config,
		private ImageCacheInterface $imageCache
	) {
	}

	public function getUri(ImageManager $imageManager, string $token, ImageRequest $imageRequest): string
	{
		return $this->imageCache->get($this->getPath(), $token)->getUri();
	}

	public function getResponse(ImageManager $imageManager, string $token, ImageRequest $imageRequest): ResponseInterface
	{
		$request = $imageRequest->withSourceFilePath($this->getPath());

		return match (strtolower($imageRequest->getTemplate())) {
			'original' => $imageManager->getOriginalImageResponse($this->getPath()),
			'download' => $imageManager->getDownloadResponse($this->getPath()),
			default => $imageManager->getCachedImageResponse($token, $request),
		};
	}

	private function getPath(): string
	{
		$path = $this->config->placeholderImageOptions['path'] ?? null;
		if (! is_string($path) || trim($path) === '') {
			return '__missing-placeholder-image__';
		}

		return $path;
	}
}
