<?php

declare(strict_types=1);

namespace ON\Image;

use ON\Config\Config;

class ImageConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			"basePath" => "i/",
		];
	}
}
