<?php

declare(strict_types=1);

namespace Intervention\Image\Interfaces;

if (! interface_exists(ModifierInterface::class, false)) {
	interface ModifierInterface
	{
		public function apply(ImageInterface $image): ImageInterface;
	}
}

if (! interface_exists(ImageInterface::class, false)) {
	interface ImageInterface
	{
	}
}
