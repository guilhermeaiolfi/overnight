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

		$this->assertContainedInRoot($file, $publicRoot);

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
		if (str_contains($uri, "\0")) {
			throw new InvalidArgumentException('Public asset URI must not contain null bytes.');
		}

		$replaced = str_replace('\\', '/', $uri);
		if (str_starts_with($replaced, '//') || str_starts_with($uri, '\\')) {
			throw new InvalidArgumentException('Public asset URI must be a relative path.');
		}

		$trimmed = trim($replaced, '/');
		if ($trimmed === '') {
			throw new InvalidArgumentException('Public asset URI must be a non-empty string.');
		}

		if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $trimmed) === 1) {
			throw new InvalidArgumentException('Public asset URI must not contain a scheme or drive prefix.');
		}

		$segments = explode('/', $trimmed);
		$windows = DIRECTORY_SEPARATOR === '\\';
		$cleanSegments = [];
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..' || ($windows && (rtrim($segment, ' .') !== $segment || str_contains($segment, ':')))) {
				throw new InvalidArgumentException('Public asset URI contains an invalid path segment.');
			}

			$cleanSegments[] = $segment;
		}

		if ($cleanSegments === []) {
			throw new InvalidArgumentException('Public asset URI must be a non-empty string.');
		}

		return implode('/', $cleanSegments);
	}

	private function assertContainedInRoot(FilePathInterface $file, DirectoryPathInterface $publicRoot): void
	{
		$rootAbs = rtrim($publicRoot->getAbsolutePath(), '/\\');
		$fileAbs = $file->getAbsolutePath();
		$prefix = $rootAbs . DIRECTORY_SEPARATOR;

		if (! str_starts_with($fileAbs, $prefix)) {
			throw new InvalidArgumentException('Public asset path escapes the public root directory.');
		}

		$realRoot = realpath($publicRoot->getAbsolutePath());
		if ($realRoot === false) {
			return;
		}

		// Check the nearest existing ancestor when the requested file is still to be created.
		$realPrefix = rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR;
		for ($path = $fileAbs; $path !== $rootAbs; $path = dirname($path)) {
			$realPath = realpath($path);
			if ($realPath !== false) {
				if ($realPath !== $realRoot && ! str_starts_with($realPath, $realPrefix)) {
					throw new InvalidArgumentException('Public asset path escapes the public root directory.');
				}

				return;
			}

			if (is_link($path)) {
				throw new InvalidArgumentException('Public asset path contains an unresolved symbolic link.');
			}

			if (dirname($path) === $path) {
				return;
			}
		}
	}
}
