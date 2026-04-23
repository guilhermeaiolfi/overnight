<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Drivers\Gd\Driver;
use ON\Config\Config;

class ImageConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			"basePath" => "i/",
			"publicPath" => "public/",
			"404ImagePath" => "404i.png",
			"templates" => [
				"custom" => CustomTemplate::class,
			],
			"driver" => Driver::class,
		];
	}
}
