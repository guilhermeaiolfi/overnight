<?php

declare(strict_types=1);

namespace ON\FileRouting;

class FileRoutingCache
{
	protected string $pagesPath;

	public function __construct(
		protected FileRoutingConfig $fileRouterConfig
	) {
		$this->pagesPath = str_replace(["\\", "/"], [ DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ], $fileRouterConfig->get('pagesPath'));
		$this->pagesPath = rtrim($this->pagesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	}

	public function get(string $file): array
	{
		$php_cache_filename = $this->getCachedPhpFilename($file);

		$template_cache_filename = $this->getCachedTemplateFilename($file);

		$file_timestamp = filemtime($file);

		if (true || ! file_exists($template_cache_filename) || $file_timestamp >= filemtime($template_cache_filename)) {
			$this->generate($file);
		}

		return [
			file_exists($php_cache_filename) ? $php_cache_filename : null,
			$template_cache_filename,
		];
	}

	public function generate(string $file): bool
	{
		$content = file_get_contents($file);

		$code = $this->split($content);

		$php_cache_filename = $this->getCachedPhpFilename($file);

		$template_cache_filename = $this->getCachedTemplateFilename($file);

		$relative = $this->getPathFromFile($file);

		// make sure the folder where it is going to be saved exists
		@mkdir($this->fileRouterConfig->get("cachePath") . dirname($relative), 0777, true);

		@unlink($php_cache_filename);
		if (strlen($code[0]) > 12) {
			// there is no reason to have a empty php file,
			// just don't include() it
			file_put_contents($php_cache_filename, $code[0]);
		}


		@unlink($template_cache_filename);
		file_put_contents($template_cache_filename, $code[1]);

		return true;
	}

	public function split($content): array
	{
		$content = trim($content);
		// it may not have php code
		$len = -2;

		if (substr($content, 0, 5) == "<?php") {

			$len = strpos($content, "?>");
		}

		$php_code = substr($content, 0, $len + 2);

		$template_code = substr($content, $len + 2, strlen($content));

		return [
			trim($php_code),
			trim($template_code),
		];
	}

	/*public function getRelativeFileFromAbsoluteFile(string $file): string
	{
		return str_replace([$this->pagesPath], [""], $file);
	}*/

	public function getPathFromFile($file): ?string
	{
		$path = str_replace([realpath($this->pagesPath) . DIRECTORY_SEPARATOR], [""], $file);

		return $path;
	}

	public function getCachedPhpFilename($file): ?string
	{
		$path = $this->getPathFromFile($file);

		return $this->fileRouterConfig->get("cachePath") . preg_replace('/\.php$/', '.code.php', $path);
	}

	public function getCachedTemplateFilename($file): ?string
	{
		$path = $this->getPathFromFile($file);

		return $this->fileRouterConfig->get("cachePath") . $path;
	}

	public function getTemplateName(string $file): string
	{
		$cachePath = str_replace(["\\", "/"], [ DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ], $this->fileRouterConfig->get("cachePath"));
		$cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$templateName = str_replace($cachePath, "", $file);
		$templateName = str_replace(DIRECTORY_SEPARATOR, "/", $templateName);

		return "filerouting::" . preg_replace('/\.php$/', '', $templateName);
	}
}
