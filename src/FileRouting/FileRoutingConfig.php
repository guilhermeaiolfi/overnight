<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\Config\Config;

class FileRoutingConfig extends Config
{
	public string $pagesPath = "src" . DIRECTORY_SEPARATOR . "Pages";
	public string $controller = "ON\FileRouting\Page\MainPage::index";
	public bool $exposeApi = false;
	public array $addons = [];
	public array $template = [
		"namespace" => "filerouting",
	];
}
