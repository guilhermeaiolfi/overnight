<?php

declare(strict_types=1);

namespace ON\Http;

use Laminas\Diactoros\UploadedFile;
use ON\Http\Exception\InvalidMultipartFormDataException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Parses multipart/form-data request bodies for methods PHP does not populate
 * in superglobals (PATCH, PUT, DELETE, etc.).
 */
final class MultipartFormDataParser
{
	/**
	 * @return array{parsedBody: array<string, mixed>, uploadedFiles: array<string, mixed>}
	 */
	public function parse(string $contentType, string $body): array
	{
		$boundary = $this->extractBoundary($contentType);
		if ($boundary === null || $body === '') {
			return [
				'parsedBody' => [],
				'uploadedFiles' => [],
			];
		}

		$parsedBody = [];
		$fileSpecs = [];

		foreach ($this->splitParts($body, $boundary) as $part) {
			if ($part === '' || $part === '--') {
				continue;
			}

			[$headers, $content] = $this->splitPartHeadersAndContent($part);
			$disposition = $this->parseContentDisposition($headers['content-disposition'] ?? '');

			if ($disposition['name'] === null) {
				continue;
			}

			$name = $disposition['name'];

			if ($disposition['hasFilename']) {
				$this->appendFileSpec(
					$fileSpecs,
					$name,
					$this->createFileSpec(
						$content,
						$disposition['filename'] ?? '',
						$headers['content-type'] ?? 'application/octet-stream',
					),
				);
				continue;
			}

			$this->setNestedValue($parsedBody, $name, $content);
		}

		return [
			'parsedBody' => $parsedBody,
			'uploadedFiles' => $fileSpecs === [] ? [] : $this->normalizeParsedUploadedFiles($fileSpecs),
		];
	}

	/**
	 * Build stream-based UploadedFile instances for parsed multipart parts.
	 *
	 * Parsed PATCH/PUT uploads are written to temp paths that are not valid
	 * move_uploaded_file() sources. Using streams keeps moveTo() working in all
	 * SAPI environments, matching the behavior of native POST uploads.
	 *
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function normalizeParsedUploadedFiles(array $files): array
	{
		$normalized = [];

		foreach ($files as $key => $value) {
			if ($value instanceof UploadedFileInterface) {
				$normalized[$key] = $value;
				continue;
			}

			if (! is_array($value)) {
				throw new InvalidMultipartFormDataException('Invalid value in parsed uploaded files specification.');
			}

			if (isset($value['tmp_name']) && is_array($value['tmp_name'])) {
				$normalized[$key] = $this->normalizeNestedParsedUploadedFiles($value);
				continue;
			}

			if (isset($value['tmp_name'])) {
				$normalized[$key] = $this->createStreamUploadedFile($value);
				continue;
			}

			$normalized[$key] = $this->normalizeParsedUploadedFiles($value);
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	private function normalizeNestedParsedUploadedFiles(array $files): array
	{
		foreach (['tmp_name', 'size', 'error'] as $requiredKey) {
			if (! isset($files[$requiredKey]) || ! is_array($files[$requiredKey])) {
				throw new InvalidMultipartFormDataException(
					'Parsed uploaded files must contain tmp_name, size, and error arrays.'
				);
			}
		}

		return $this->normalizeParsedUploadedFileTree(
			$files['tmp_name'],
			$files['size'],
			$files['error'],
			is_array($files['name'] ?? null) ? $files['name'] : null,
			is_array($files['type'] ?? null) ? $files['type'] : null,
		);
	}

	/**
	 * @param array<string|int, mixed> $tmpNameTree
	 * @param array<string|int, mixed> $sizeTree
	 * @param array<string|int, mixed> $errorTree
	 * @param array<string|int, mixed>|null $nameTree
	 * @param array<string|int, mixed>|null $typeTree
	 * @return array<string|int, mixed>
	 */
	private function normalizeParsedUploadedFileTree(
		array $tmpNameTree,
		array $sizeTree,
		array $errorTree,
		?array $nameTree = null,
		?array $typeTree = null,
	): array {
		$normalized = [];

		foreach ($tmpNameTree as $key => $value) {
			if (is_array($value)) {
				$normalized[$key] = $this->normalizeParsedUploadedFileTree(
					$tmpNameTree[$key],
					$sizeTree[$key],
					$errorTree[$key],
					is_array($nameTree[$key] ?? null) ? $nameTree[$key] : null,
					is_array($typeTree[$key] ?? null) ? $typeTree[$key] : null,
				);
				continue;
			}

			$normalized[$key] = $this->createStreamUploadedFile([
				'tmp_name' => $tmpNameTree[$key],
				'size' => $sizeTree[$key],
				'error' => $errorTree[$key],
				'name' => $nameTree[$key] ?? null,
				'type' => $typeTree[$key] ?? null,
			]);
		}

		return $normalized;
	}

	/**
	 * @param array{tmp_name: mixed, size: mixed, error: mixed, name?: mixed, type?: mixed} $spec
	 */
	private function createStreamUploadedFile(array $spec): UploadedFileInterface
	{
		$error = (int) $spec['error'];
		$size = (int) $spec['size'];
		$name = is_string($spec['name'] ?? null) ? $spec['name'] : null;
		$type = is_string($spec['type'] ?? null) ? $spec['type'] : null;

		if ($error !== UPLOAD_ERR_OK) {
			return new UploadedFile('', $size, $error, $name, $type);
		}

		$stream = fopen('php://temp', 'rb+');
		if ($stream === false) {
			throw new InvalidMultipartFormDataException('Unable to create a stream for an uploaded part.');
		}

		$tmpPath = (string) ($spec['tmp_name'] ?? '');
		if ($tmpPath !== '' && is_readable($tmpPath)) {
			$content = file_get_contents($tmpPath);
			if ($content === false) {
				fclose($stream);
				throw new InvalidMultipartFormDataException('Unable to read a temporary uploaded part.');
			}

			if ($content !== '' && fwrite($stream, $content) === false) {
				fclose($stream);
				throw new InvalidMultipartFormDataException('Unable to buffer an uploaded part.');
			}

			@unlink($tmpPath);
		}

		rewind($stream);

		return new UploadedFile($stream, $size, UPLOAD_ERR_OK, $name, $type);
	}

	private function extractBoundary(string $contentType): ?string
	{
		if (! preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $matches)) {
			return null;
		}

		$boundary = trim($matches[1] !== '' ? $matches[1] : $matches[2]);

		return $boundary !== '' ? $boundary : null;
	}

	/**
	 * @return list<string>
	 */
	private function splitParts(string $body, string $boundary): array
	{
		$delimiter = preg_quote($boundary, '/');
		$parts = preg_split('/(?:^|\r\n|\n)--' . $delimiter . '(?:--)?[ \t]*(?:\r\n|\n|$)/', $body);
		if (! is_array($parts)) {
			return [];
		}

		array_shift($parts);

		if ($parts === []) {
			return [];
		}

		array_pop($parts);

		return $parts;
	}

	/**
	 * @return array{0: array<string, string>, 1: string}
	 */
	private function splitPartHeadersAndContent(string $part): array
	{
		$segments = preg_split("/\r\n\r\n|\n\n/", $part, 2);
		if (! is_array($segments) || count($segments) !== 2) {
			return [[], ''];
		}

		$headers = [];
		foreach (preg_split("/\r\n|\n/", $segments[0]) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || ! str_contains($line, ':')) {
				continue;
			}

			[$name, $value] = explode(':', $line, 2);
			$headers[strtolower(trim($name))] = trim($value);
		}

		return [$headers, $segments[1]];
	}

	/**
	 * @return array{name: ?string, filename: ?string, hasFilename: bool}
	 */
	private function parseContentDisposition(string $header): array
	{
		if ($header === '') {
			return ['name' => null, 'filename' => null, 'hasFilename' => false];
		}

		$name = null;
		$filename = null;
		$hasFilename = false;

		if (preg_match('/name="([^"]*)"/i', $header, $matches)) {
			$name = $this->decodeFieldName($matches[1]);
		} elseif (preg_match("/name=([^;]+)/i", $header, $matches)) {
			$name = $this->decodeFieldName(trim($matches[1], " \t\""));
		}

		if (preg_match('/filename="([^"]*)"/i', $header, $matches)) {
			$filename = $matches[1];
			$hasFilename = true;
		} elseif (preg_match("/filename=([^;]+)/i", $header, $matches)) {
			$filename = trim($matches[1], " \t\"");
			$hasFilename = true;
		}

		return [
			'name' => $name,
			'filename' => $filename !== '' ? $filename : null,
			'hasFilename' => $hasFilename,
		];
	}

	private function decodeFieldName(string $name): string
	{
		return str_replace(['%5B', '%5D'], ['[', ']'], $name);
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function setNestedValue(array &$target, string $name, mixed $value): void
	{
		if (! str_contains($name, '[')) {
			$target[$name] = $value;

			return;
		}

		$keys = $this->parseFieldPath($name);
		if ($keys === []) {
			return;
		}

		$current = &$target;
		foreach ($keys as $index => $key) {
			if ($index === count($keys) - 1) {
				if ($key === '') {
					$current[] = $value;
				} else {
					$current[$key] = $value;
				}
				break;
			}

			if ($key === '') {
				$current[] = [];
				$key = array_key_last($current);
			}

			if (! isset($current[$key]) || ! is_array($current[$key])) {
				$current[$key] = [];
			}

			$current = &$current[$key];
		}
	}

	/**
	 * @return array{tmp_name: string, size: int, error: int, name: string, type: string}
	 */
	private function createFileSpec(string $content, string $filename, string $mimeType): array
	{
		if ($filename === '') {
			return [
				'tmp_name' => '',
				'size' => 0,
				'error' => UPLOAD_ERR_NO_FILE,
				'name' => '',
				'type' => $mimeType,
			];
		}

		$tmpPath = tempnam(sys_get_temp_dir(), 'onupload_');
		if ($tmpPath === false) {
			throw new InvalidMultipartFormDataException('Unable to create a temporary file for an uploaded part.');
		}

		if ($content !== '' && file_put_contents($tmpPath, $content) === false) {
			@unlink($tmpPath);
			throw new InvalidMultipartFormDataException('Unable to write an uploaded file to disk.');
		}

		return [
			'tmp_name' => $tmpPath,
			'size' => strlen($content),
			'error' => UPLOAD_ERR_OK,
			'name' => $filename,
			'type' => $mimeType,
		];
	}

	/**
	 * @param array<string, mixed> $files
	 * @param array{tmp_name: string, size: int, error: int, name: string, type: string} $spec
	 */
	private function appendFileSpec(array &$files, string $fieldName, array $spec): void
	{
		$keys = $this->parseFieldPath($fieldName);
		if ($keys === []) {
			return;
		}

		$rootKey = array_shift($keys);

		if (! isset($files[$rootKey]) || ! is_array($files[$rootKey])) {
			$files[$rootKey] = [];
		}

		$concreteKeys = $this->materializeFileKeys($files, $rootKey, $keys);

		foreach ($spec as $property => $value) {
			if ($concreteKeys === []) {
				$files[$rootKey][$property] = $value;
				continue;
			}

			if (! isset($files[$rootKey][$property]) || ! is_array($files[$rootKey][$property])) {
				$files[$rootKey][$property] = [];
			}

			$current = &$files[$rootKey][$property];
			foreach ($concreteKeys as $index => $key) {
				if ($index === count($concreteKeys) - 1) {
					$current[$key] = $value;
					break;
				}

				if (! isset($current[$key]) || ! is_array($current[$key])) {
					$current[$key] = [];
				}

				$current = &$current[$key];
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function parseFieldPath(string $name): array
	{
		if (! preg_match('/^([^\[]*)((?:\[[^\]]*\])*)$/', $name, $matches)) {
			return [$name];
		}

		$keys = [$matches[1]];
		if ($matches[2] !== '') {
			preg_match_all('/\[([^\]]*)\]/', $matches[2], $segments);
			$keys = array_merge($keys, $segments[1] ?? []);
		}

		return array_values(array_filter(
			$keys,
			static fn (string $key, int $index): bool => $index === 0 || $key !== '' || str_contains($name, '[]'),
			ARRAY_FILTER_USE_BOTH,
		));
	}

	/**
	 * @param array<string, mixed> $files
	 * @param list<string> $keys
	 * @return list<string|int>
	 */
	private function materializeFileKeys(array &$files, string $rootKey, array $keys): array
	{
		if (! in_array('', $keys, true)) {
			return $keys;
		}

		if (! isset($files[$rootKey]['name']) || ! is_array($files[$rootKey]['name'])) {
			$files[$rootKey]['name'] = [];
		}

		$current = &$files[$rootKey]['name'];
		$concreteKeys = [];

		foreach ($keys as $index => $key) {
			if ($key === '') {
				$key = count($current);
			}

			$concreteKeys[] = $key;

			if ($index === count($keys) - 1) {
				break;
			}

			if (! isset($current[$key]) || ! is_array($current[$key])) {
				$current[$key] = [];
			}

			$current = &$current[$key];
		}

		return $concreteKeys;
	}
}
