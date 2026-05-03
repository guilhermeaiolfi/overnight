<?php

declare(strict_types=1);

namespace ON\FS;

use InvalidArgumentException;

final class PublicAssetManager
{
	public function __construct(
		private PathRegistry $paths
	) {
	}

	public function fromUri(string $uri): PublicAssetInterface
	{
		$normalizedUri = $this->normalizeUri($uri);
		$publicRoot = $this->paths->get('public');
		$file = PathFile::from($normalizedUri, $publicRoot);

		return new PublicAsset($normalizedUri, $file);
	}

	public function fromUriAndFile(string $uri, string|FilePathInterface $file): PublicAssetInterface
	{
		$normalizedUri = $this->normalizeUri($uri);
		$filePath = $file instanceof FilePathInterface ? $file : PathFile::from($file, $this->paths->get('project'));

		return new PublicAsset($normalizedUri, $filePath);
	}

	private function normalizeUri(string $uri): string
	{
		$normalizedUri = trim(str_replace('\\', '/', $uri), '/');
		if ($normalizedUri === '') {
			throw new InvalidArgumentException('Public asset URI must be a non-empty string.');
		}

		return $normalizedUri;
	}
}
