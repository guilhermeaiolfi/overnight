<?php

declare(strict_types=1);

namespace ON\Image;

use ON\Image\Cache\ImageCacheInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;

final class DefaultPlaceholderImage implements PlaceholderImageInterface
{
	public function __construct(
		private ImageConfig $config,
		private ?ImageCacheInterface $imageCache = null
	) {
	}

	public function getUri(ImageManager $imageManager, string $token, ImageRequest $imageRequest): string
	{
		return rtrim($this->config->getPublicImagesUri(), '/') . '/' . $token . '.svg';
	}

	public function getResponse(ImageManager $imageManager, string $token, ImageRequest $imageRequest): ResponseInterface
	{
		[$width, $height] = $this->resolveDimensions($imageRequest);
		$content = $this->buildSvg($imageRequest, $width, $height);
		$etag = md5($content);
		$notModified = isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag;

		if ($notModified) {
			return (new Response())
				->withStatus(304)
				->withHeader('ETag', $etag)
				->withHeader('Cache-Control', 'max-age=' . ($this->getCacheLifetime() * 60) . ', public');
		}

		$factory = new StreamFactory();
		$body = $factory->createStream($content);

		return (new Response())
			->withHeader('Content-Type', 'image/svg+xml')
			->withHeader('ETag', $etag)
			->withHeader('Cache-Control', 'max-age=' . ($this->getCacheLifetime() * 60) . ', public')
			->withHeader('Content-Length', (string) strlen($content))
			->withBody($body);
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function resolveDimensions(ImageRequest $imageRequest): array
	{
		$templateKey = strtolower($imageRequest->getTemplate());
		$options = $this->config->placeholderImageOptions;
		$defaultWidth = $this->normalizeDimension($options['width'] ?? null, 400);
		$defaultHeight = $this->normalizeDimension($options['height'] ?? null, 300);

		$templateConfig = $options['templates'][$templateKey] ?? null;
		$width = $this->normalizeDimension(is_array($templateConfig) ? ($templateConfig['width'] ?? null) : null, $defaultWidth);
		$height = $this->normalizeDimension(is_array($templateConfig) ? ($templateConfig['height'] ?? null) : null, $defaultHeight);

		if ($templateKey !== 'custom' || ! is_string($imageRequest->getOptions())) {
			return [$width, $height];
		}

		foreach (explode('|', $imageRequest->getOptions()) as $command) {
			if ($command === '' || ! str_contains($command, ':')) {
				continue;
			}

			[$method, $args] = explode(':', $command, 2);
			if (! in_array(strtolower($method), ['cover', 'resize', 'scale'], true)) {
				continue;
			}

			$argValues = explode('/', $args, 2)[0];
			[$candidateWidth, $candidateHeight] = array_pad(explode(',', $argValues), 2, null);
			$width = $this->normalizeDimension($candidateWidth, $width);
			$height = $this->normalizeDimension($candidateHeight, $height);
		}

		return [$width, $height];
	}

	private function buildSvg(ImageRequest $imageRequest, int $width, int $height): string
	{
		$options = $this->config->placeholderImageOptions;
		$background = $this->escapeSvgText((string) ($options['background'] ?? '#f3f4f6'));
		$foreground = $this->escapeSvgText((string) ($options['foreground'] ?? '#9ca3af'));
		$label = $this->escapeSvgText((string) ($options['label'] ?? 'Image unavailable'));
		$showFilename = (bool) ($options['showFilename'] ?? false);
		$filename = $this->escapeSvgText(basename($imageRequest->getSourceFilePath()));
		$padding = max(10, (int) round(min($width, $height) * 0.06));
		$strokeWidth = max(2, (int) round(min($width, $height) * 0.02));
		$titleFontSize = max(14, (int) round(min($width, $height) * 0.09));
		$captionFontSize = max(10, (int) round(min($width, $height) * 0.05));
		$filenameAllowed = $showFilename && $height >= 160;
		$labelY = $filenameAllowed ? (int) round($height * 0.77) : min($height - $padding, (int) round($height * 0.8));
		$fileY = min($height - $padding, $labelY + $captionFontSize + 8);
		$frameX = $strokeWidth;
		$frameY = $strokeWidth;
		$frameWidth = max(1, $width - ($strokeWidth * 2));
		$frameHeight = max(1, $height - ($strokeWidth * 2));
		$iconBottomY = max($padding + 24, $labelY - max(22, $titleFontSize + 12));
		$iconTopY = max($padding + 4, (int) round($height * 0.22));
		$iconLeftX = max($padding + 8, (int) round($width * 0.2));
		$iconRightX = min($width - $padding - 8, (int) round($width * 0.82));
		$iconMidX = (int) round(($iconLeftX + $iconRightX) / 2);
		$iconInnerRightX = min($iconRightX, (int) round($width * 0.68));
		$iconPeakY = max($iconTopY + 10, (int) round($iconTopY + (($iconBottomY - $iconTopY) * 0.32)));
		$iconValleyY = max($iconPeakY + 8, (int) round($iconTopY + (($iconBottomY - $iconTopY) * 0.75)));
		$circleRadius = max(6, (int) round(min($width, $height) * 0.045));
		$circleCx = max($iconLeftX + $circleRadius, (int) round($width * 0.36));
		$circleCy = max($padding + $circleRadius, (int) round($iconTopY + 6));

		$filenameText = '';
		if ($filenameAllowed) {
			$filenameText = PHP_EOL . '  <text x="50%" y="' . $fileY . '" text-anchor="middle" font-family="system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif" font-size="' . $captionFontSize . '" fill="' . $foreground . '" opacity="0.82">' . $filename . '</text>';
		}

		return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}" role="img" aria-label="{$label}">
  <rect width="100%" height="100%" fill="{$background}"/>
  <g fill="none" stroke="{$foreground}" stroke-width="{$strokeWidth}" stroke-linecap="round" stroke-linejoin="round" opacity="0.92">
    <rect x="{$frameX}" y="{$frameY}" width="{$frameWidth}" height="{$frameHeight}" rx="12" ry="12"/>
    <path d="M{$iconLeftX} {$iconBottomY} L{$iconMidX} {$iconPeakY} L{$iconInnerRightX} {$iconBottomY} L{$iconRightX} {$iconValleyY}"/>
    <circle cx="{$circleCx}" cy="{$circleCy}" r="{$circleRadius}"/>
  </g>
  <text x="50%" y="{$labelY}" text-anchor="middle" font-family="system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" font-size="{$titleFontSize}" fill="{$foreground}">{$label}</text>{$filenameText}
</svg>
SVG;
	}

	private function normalizeDimension(mixed $value, int $fallback): int
	{
		if (is_numeric($value) && (int) $value > 0) {
			return (int) $value;
		}

		return $fallback;
	}

	private function escapeSvgText(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	private function getCacheLifetime(): int
	{
		return (int) $this->config->get('cache.lifetime', 0);
	}
}
