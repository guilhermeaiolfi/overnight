<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\View\ViewConfig;

class FileRoutingCache
{
	protected string $pagesPath;

	public function __construct(
		protected FileRoutingConfig $fileRouterConfig,
		protected ViewConfig $viewConfig
	) {
		$this->pagesPath = str_replace(["\\", "/"], [ DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ], $fileRouterConfig->get('pagesPath'));
		$this->pagesPath = rtrim($this->pagesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	}

	public function get(string $file): array
	{
		$metadata = $this->getFreshMetadata($file);
		if ($metadata !== null) {
			return [
				$metadata['controller'] ?? null,
				$metadata['template'],
				$metadata['lang'],
			];
		}

		$generated = $this->generate($file);

		return [
			$generated['controller'],
			$generated['template'],
			$generated['lang'],
		];
	}

	public function generate(string $file): array
	{
		$content = file_get_contents($file);
		$code = $this->split($content);
		$template = $this->parseTemplate($code[1]);

		$php_cache_filename = $this->getCachedPhpFilename($file);

		$template_cache_filename = $this->getCachedTemplateFilename($file, $template['lang']);
		$metadata_cache_filename = $this->getCachedMetadataFilename($file);

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
		file_put_contents($template_cache_filename, $template['content']);

		clearstatcache(true, $file);
		$metadata = [
			'source_mtime' => filemtime($file),
			'source_size' => filesize($file),
			'controller' => file_exists($php_cache_filename) ? $php_cache_filename : null,
			'template' => $template_cache_filename,
			'lang' => $template['lang'],
		];
		file_put_contents($metadata_cache_filename, "<?php\n\nreturn " . var_export($metadata, true) . ";\n");

		return $metadata;
	}

	protected function getFreshMetadata(string $file): ?array
	{
		$metadata_cache_filename = $this->getCachedMetadataFilename($file);
		clearstatcache(true, $metadata_cache_filename);
		if (! file_exists($metadata_cache_filename)) {
			return null;
		}

		$metadata = include $metadata_cache_filename;
		if (! is_array($metadata)) {
			return null;
		}

		clearstatcache(true, $file);
		if (($metadata['source_mtime'] ?? null) !== filemtime($file)) {
			return null;
		}

		if (($metadata['source_size'] ?? null) !== filesize($file)) {
			return null;
		}

		clearstatcache(true, $metadata['template'] ?? '');
		if (empty($metadata['template']) || ! file_exists($metadata['template'])) {
			return null;
		}

		clearstatcache(true, $metadata['controller'] ?? '');
		if (! empty($metadata['controller']) && ! file_exists($metadata['controller'])) {
			return null;
		}

		return $metadata;
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
		$file = realpath($file) ?: $file;
		$path = str_replace([realpath($this->pagesPath) . DIRECTORY_SEPARATOR], [""], $file);

		return $path;
	}

	public function getCachedPhpFilename($file): ?string
	{
		$path = $this->getPathFromFile($file);

		return $this->fileRouterConfig->get("cachePath") . preg_replace('/\.php$/', '.code.php', $path);
	}

	public function getCachedTemplateFilename($file, ?string $lang = null): ?string
	{
		$path = $this->getPathFromFile($file);
		$extension = $this->getTemplateExtension($lang);
		$path = preg_replace('/\.php$/', '.' . $extension, $path);

		return $this->fileRouterConfig->get("cachePath") . $path;
	}

	public function getCachedMetadataFilename($file): ?string
	{
		$path = $this->getPathFromFile($file);

		return $this->fileRouterConfig->get("cachePath") . preg_replace('/\.php$/', '.meta.php', $path);
	}

	public function getTemplateName(string $file): string
	{
		$cachePath = str_replace(["\\", "/"], [ DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ], $this->fileRouterConfig->get("cachePath"));
		$cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$templateName = str_replace($cachePath, "", $file);
		$templateName = str_replace(DIRECTORY_SEPARATOR, "/", $templateName);

		return $this->fileRouterConfig->get("template.namespace", "filerouting") . "::" . preg_replace('/\.[^.]+$/', '', $templateName);
	}

	protected function parseTemplate(string $content): array
	{
		if (preg_match('/^\s*<template\s+lang=(["\'])([a-zA-Z0-9_-]+)\1\s*>(.*)<\/template>\s*$/s', $content, $matches)) {
			return [
				'lang' => strtolower($matches[2]),
				'content' => trim($matches[3]),
			];
		}

		return [
			'lang' => null,
			'content' => $content,
		];
	}

	protected function getTemplateExtension(?string $lang): string
	{
		if (is_string($lang) && $lang !== '') {
			return $this->viewConfig->get("formats.html.renderers.{$lang}.extension", $lang);
		}

		return $this->viewConfig->get('templates.extension', 'phtml');
	}
}
