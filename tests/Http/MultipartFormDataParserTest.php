<?php

declare(strict_types=1);

namespace Tests\ON\Http;

use ON\Http\MultipartFormDataParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class MultipartFormDataParserTest extends TestCase
{
	public function testParsesTextFieldsAndNestedFiles(): void
	{
		$boundary = 'test-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="data"',
			'',
			'{"title":"Updated title"}',
			'--' . $boundary,
			'Content-Disposition: form-data; name="images[0][file_id]"; filename="photo.jpg"',
			'Content-Type: image/jpeg',
			'',
			'binary-image-data',
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$this->assertSame('{"title":"Updated title"}', $result['parsedBody']['data']);
		$this->assertInstanceOf(
			UploadedFileInterface::class,
			$result['uploadedFiles']['images'][0]['file_id'],
		);
		$this->assertSame('photo.jpg', $result['uploadedFiles']['images'][0]['file_id']->getClientFilename());
	}

	public function testPreservesTrailingNewlinesAndBoundaryTextInsideContent(): void
	{
		$boundary = 'test-boundary';
		$value = "first line\r\n--{$boundary} inside content\r\n";
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="notes"',
			'',
			$value,
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$this->assertSame($value, $result['parsedBody']['notes']);
	}

	public function testAppendsRepeatedArrayFields(): void
	{
		$boundary = 'test-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="tags[]"',
			'',
			'alpha',
			'--' . $boundary,
			'Content-Disposition: form-data; name="tags[]"',
			'',
			'beta',
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$this->assertSame(['alpha', 'beta'], $result['parsedBody']['tags']);
	}

	public function testAppendsRepeatedArrayFiles(): void
	{
		$boundary = 'test-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="attachments[]"; filename="one.txt"',
			'Content-Type: text/plain',
			'',
			'one',
			'--' . $boundary,
			'Content-Disposition: form-data; name="attachments[]"; filename="two.txt"',
			'Content-Type: text/plain',
			'',
			'two',
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$this->assertSame('one.txt', $result['uploadedFiles']['attachments'][0]->getClientFilename());
		$this->assertSame('two.txt', $result['uploadedFiles']['attachments'][1]->getClientFilename());
	}

	public function testParsedUploadCanBeMovedToDestination(): void
	{
		$boundary = 'test-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="images[0][file_id]"; filename="photo.jpg"',
			'Content-Type: image/jpeg',
			'',
			'binary-image-data',
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$file = $result['uploadedFiles']['images'][0]['file_id'];
		$target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-move-test-' . bin2hex(random_bytes(8)) . '.jpg';

		$file->moveTo($target);

		$this->assertFileExists($target);
		$this->assertSame('binary-image-data', file_get_contents($target));

		@unlink($target);
	}

	public function testRepresentsEmptyFileInputAsNoFileUpload(): void
	{
		$boundary = 'test-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="avatar"; filename=""',
			'Content-Type: application/octet-stream',
			'',
			'',
			'--' . $boundary . '--',
			'',
		]);

		$result = (new MultipartFormDataParser())->parse(
			'multipart/form-data; boundary=' . $boundary,
			$body,
		);

		$this->assertSame(UPLOAD_ERR_NO_FILE, $result['uploadedFiles']['avatar']->getError());
		$this->assertSame('', $result['uploadedFiles']['avatar']->getClientFilename());
	}
}
