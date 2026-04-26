<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Config\Config;

class FileRoutingConfig extends Config
{
	public string $pagesPath = "src" . DIRECTORY_SEPARATOR . "Pages";
	public string $cachePath = "var" . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "filerouting" . DIRECTORY_SEPARATOR;
	public string $controller = "ON\FileRouting\Page\MainPage::index";
	public array $template = [
		"namespace" => "filerouting",
	];
}
