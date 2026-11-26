<?php

declare(strict_types=1);

namespace ON\FileRouting;

use ON\View\ViewConfig;
use RuntimeException;

class FileRoutingCache
{
	protected string $pagesPath;

	protected string $cachePath;

	public function __construct(
		protected FileRoutingConfig $fileroutingCfg,
		protected ViewConfig $viewConfig
	) {
		$this->pagesPath = str_replace(["\\", "/"], [ DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR ], $fileroutingCfg->get('pagesPath'));
		$this->pagesPath = rtrim($this->pagesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		$this->cachePath = $fileroutingCfg->get("cachePath");
	}

	public function get(string $file): array
	{
		$metadata = $this->getFreshMetadata($file);
		if ($metadata !== null) {
			return [
				$metadata['controller'] ?? null,
				$metadata['template'],
				$metadata['lang'],
				$metadata['page_meta'] ?? [],
			];
		}

		$generated = $this->generate($file);

		return [
			$generated['controller'],
			$generated['template'],
			$generated['lang'],
			$generated['page_meta'] ?? [],
		];
	}

	public function generate(string $file): array
	{
		$content = file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException(sprintf('Unable to read file routing source "%s".', $file));
		}

		[$php_code, $template_code] = $this->splitSourceContent($content);
		$page_meta = $this->extractPageMeta($php_code);
		$template = $this->parseTemplate($template_code);

		$php_cache_filename = $this->getCachedPhpFilename($file);

		$template_cache_filename = $this->getCachedTemplateFilename($file, $template['lang']);
		$metadata_cache_filename = $this->getCachedMetadataFilename($file);

		$targetDir = dirname($metadata_cache_filename);
		if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $targetDir));
		}

		$controller = null;
		if ($this->hasPhpCode($php_code)) {
			// there is no reason to have an empty php file,
			// just don't include() it
			$this->writeFileAtomically($php_cache_filename, $php_code);
			$controller = $php_cache_filename;
		} elseif (file_exists($php_cache_filename)) {
			@unlink($php_cache_filename);
		}

		$this->writeFileAtomically($template_cache_filename, $template['content']);

		clearstatcache(true, $file);
		$metadata = [
			'source_mtime' => filemtime($file),
			'source_size' => filesize($file),
			'controller' => $controller,
			'template' => $template_cache_filename,
			'lang' => $template['lang'],
			'page_meta' => $page_meta,
		];
		$this->writeFileAtomically($metadata_cache_filename, "<?php\n\nreturn " . var_export($metadata, true) . ";\n");

		return $metadata;
	}

	protected function hasPhpCode(string $phpCode): bool
	{
		if ($phpCode === '') {
			return false;
		}

		$inner = preg_replace('/^<\?php\b|\?>$/', '', $phpCode);

		return is_string($inner) && trim($inner) !== '';
	}

	protected function writeFileAtomically(string $filename, string $content): void
	{
		$directory = dirname($filename);
		if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Unable to create file routing cache directory "%s".', $directory));
		}

		$tempFile = $directory . DIRECTORY_SEPARATOR . basename($filename) . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$bytes = file_put_contents($tempFile, $content, LOCK_EX);
		if ($bytes === false) {
			@unlink($tempFile);

			throw new RuntimeException(sprintf('Unable to write temporary cache file "%s".', $tempFile));
		}

		if (! rename($tempFile, $filename)) {
			@unlink($tempFile);

			throw new RuntimeException(sprintf('Unable to move temporary cache file "%s" to "%s".', $tempFile, $filename));
		}
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
		[$php_code, $template_code] = $this->splitSourceContent($content);
		$template = $this->parseTemplate($template_code);

		return [
			$php_code,
			$template['content'],
		];
	}

	protected function splitSourceContent(string $content): array
	{
		$content = trim($content);

		if (! str_starts_with($content, '<?php')) {
			return [
				'',
				$content,
			];
		}

		$closingPos = strpos($content, '?>');
		if ($closingPos === false) {
			return [
				$content,
				'',
			];
		}

		$php_code = substr($content, 0, $closingPos + 2);
		$template_code = substr($content, $closingPos + 2);

		return [
			trim($php_code),
			trim($template_code),
		];
	}

	public function extractPageMeta(string &$php_code): array
	{
		if (! preg_match('/\/\*---\s*(.*?)\s*---\*\//s', $php_code, $matches)) {
			return [];
		}

		$decoded = json_decode(trim($matches[1]), true);
		if (! is_array($decoded)) {
			throw new RuntimeException('Invalid file routing page metadata JSON.');
		}

		$php_code = trim(str_replace($matches[0], '', $php_code));

		return $decoded;
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

		return $this->cachePath . DIRECTORY_SEPARATOR . preg_replace('/\.php$/', '.code.php', $path);
	}

	public function getCachedTemplateFilename($file, ?string $lang = null): ?string
	{
		$path = $this->getPathFromFile($file);
		$extension = $this->getTemplateExtension($lang);
		$path = preg_replace('/\.php$/', '.' . $extension, $path);

		return $this->cachePath . DIRECTORY_SEPARATOR . $path;
	}

	public function getCachedMetadataFilename($file): ?string
	{
		$path = $this->getPathFromFile($file);

		return $this->cachePath . DIRECTORY_SEPARATOR . preg_replace('/\.php$/', '.meta.php', $path);
	}

	public function getTemplateName(string $file, bool $preserveExtension = false): string
	{
		$templateName = str_replace($this->cachePath, "", $file);
		$templateName = str_replace(DIRECTORY_SEPARATOR, "/", $templateName);
		$templateName = ltrim($templateName, "/");

		if (! $preserveExtension) {
			$templateName = preg_replace('/\.[^.]+$/', '', $templateName);
		}

		return $this->fileroutingCfg->get("template.namespace", "filerouting") . "::" . $templateName;
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
