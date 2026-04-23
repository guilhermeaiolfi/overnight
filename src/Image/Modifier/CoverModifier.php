<?php

declare(strict_types=1);

namespace ON\Image\Modifier;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;

// TODO: WIP
class CoverModifier implements ModifierInterface
{
	public function __construct(
		public ?int $width = null,
		public ?int $height = null,
		public string $position = 'center'
	) {
	}

	public function apply(ImageInterface $image): ImageInterface
	{
		$width = $this->width;
		$height = $this->height;

		if ($width === null && $height === null) {
			return $image;
		}

		return $image->cover(
			width: $width ?? $height,
			height: $height ?? $width,
			position: $this->position
		);
	}
}
