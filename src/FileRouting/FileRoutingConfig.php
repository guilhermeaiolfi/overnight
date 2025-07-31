<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Config\Config;

class FileRoutingConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			"pagesPath" => "src" . DIRECTORY_SEPARATOR . "Pages",
			"cachePath" => "var" . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "filerouting" . DIRECTORY_SEPARATOR,
			"controller" => "ON\FileRouting\Page\MainPage::index",
		];
	}
}
