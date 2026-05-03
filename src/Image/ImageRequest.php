<?php

declare(strict_types=1);

namespace ON\Image;

final class ImageRequest
{
	public function __construct(
		private string $sourceFilePath,
		private string $template,
		private mixed $options = null
	) {
	}

	public static function fromArray(array $payload): ?self
	{
		$path = $payload['path'] ?? null;
		$template = $payload['template'] ?? null;

		if (! is_string($path) || $path === '' || ! is_string($template) || $template === '') {
			return null;
		}

		return new self($path, $template, $payload['options'] ?? null);
	}

	public function getSourceFilePath(): string
	{
		return $this->sourceFilePath;
	}

	public function getTemplate(): string
	{
		return $this->template;
	}

	public function getOptions(): mixed
	{
		return $this->options;
	}

	public function withSourceFilePath(string $sourceFilePath): self
	{
		return new self($sourceFilePath, $this->template, $this->options);
	}

	public function toArray(): array
	{
		return [
			'sourceFilePath' => $this->sourceFilePath,
			'template' => $this->template,
			'options' => $this->options,
		];
	}
}
