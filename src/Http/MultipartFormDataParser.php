<?php

declare(strict_types=1);

namespace ON\Http;

use InvalidArgumentException;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use ON\Http\Exception\InvalidMultipartFormDataException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Parses multipart/form-data request bodies for methods PHP does not populate
 * in superglobals (PATCH, PUT, DELETE, etc.).
 */
final class MultipartFormDataParser
{
	private readonly int $maxFileUploads;

	public function __construct(?int $maxFileUploads = null)
	{
		$configuredLimit = ini_get('max_file_uploads');
		$this->maxFileUploads = $maxFileUploads ?? ($configuredLimit === false ? 20 : (int) $configuredLimit);
		if ($this->maxFileUploads < 0) {
			throw new InvalidArgumentException('The multipart file limit must be zero or greater.');
		}
	}

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
		$uploadedFiles = [];
		$fileCount = 0;

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
				$filename = $disposition['filename'] ?? '';
				if ($filename !== '' && ++$fileCount > $this->maxFileUploads) {
					throw new InvalidMultipartFormDataException('The multipart request contains too many uploaded files.');
				}

				$this->setNestedValue(
					$uploadedFiles,
					$name,
					$this->createUploadedFile(
						$content,
						$filename,
						$headers['content-type'] ?? 'application/octet-stream',
					),
				);

				continue;
			}

			$this->setNestedValue($parsedBody, $name, $content);
		}

		return [
			'parsedBody' => $parsedBody,
			'uploadedFiles' => $uploadedFiles,
		];
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

	private function createUploadedFile(string $content, string $filename, string $mimeType): UploadedFileInterface
	{
		if ($filename === '') {
			return new UploadedFile('', 0, UPLOAD_ERR_NO_FILE, $filename, $mimeType);
		}

		// The temporary file is removed when its stream closes or is released.
		$resource = @tmpfile();
		if ($resource === false) {
			throw new InvalidMultipartFormDataException('Unable to create a temporary file for an uploaded part.');
		}

		$size = strlen($content);
		if (@fwrite($resource, $content) !== $size || ! @rewind($resource)) {
			fclose($resource);

			throw new InvalidMultipartFormDataException('Unable to write an uploaded file to disk.');
		}

		// A stream lets moveTo() work across SAPIs for files created by this parser.
		$stream = (new StreamFactory())->createStreamFromResource($resource);

		return new UploadedFile($stream, $size, UPLOAD_ERR_OK, $filename, $mimeType);
	}

	/**
	 * @return non-empty-list<string>
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
}
