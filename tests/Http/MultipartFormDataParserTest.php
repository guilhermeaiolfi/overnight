<?php

declare(strict_types=1);

namespace Tests\ON\Http;

use ON\Http\Exception\InvalidMultipartFormDataException;
use ON\Http\MultipartFormDataParser;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

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
		$file = $result['uploadedFiles']['images'][0]['file_id'];
		$this->assertInstanceOf(UploadedFileInterface::class, $file);
		$this->assertSame('photo.jpg', $file->getClientFilename());
		$this->assertSame('image/jpeg', $file->getClientMediaType());
		$this->assertSame(strlen('binary-image-data'), $file->getSize());
		$this->assertSame(UPLOAD_ERR_OK, $file->getError());
		$this->assertSame('binary-image-data', $file->getStream()->getContents());
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

	public function testPreservesSparseArrayKeysAndReplacesRepeatedNamedFiles(): void
	{
		$body = "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"nested[files]%5B1%5D\"; filename=\"old.txt\"\r\n\r\nold\r\n"
			. "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"nested[files][]\"; filename=\"append.txt\"\r\n\r\nappend\r\n"
			. "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"nested[files][1]\"; filename=\"new.txt\"\r\n\r\nnew\r\n"
			. "--boundary--\r\n";

		$result = (new MultipartFormDataParser())->parse('multipart/form-data; boundary=boundary', $body);
		$files = $result['uploadedFiles']['nested']['files'];

		$this->assertSame([1, 2], array_keys($files));
		$this->assertSame('new.txt', $files[1]->getClientFilename());
		$this->assertSame('new', $files[1]->getStream()->getContents());
		$this->assertSame('append.txt', $files[2]->getClientFilename());
	}

	public function testLimitsNamedUploadsAndIgnoresEmptyFileInputs(): void
	{
		$parser = new MultipartFormDataParser(maxFileUploads: 1);
		$contentType = 'multipart/form-data; boundary=boundary';
		$body = "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"empty\"; filename=\"\"\r\n\r\n\r\n"
			. "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"files[]\"; filename=\"one.txt\"\r\n\r\none\r\n";

		$result = $parser->parse($contentType, $body . "--boundary--\r\n");
		$this->assertSame(UPLOAD_ERR_NO_FILE, $result['uploadedFiles']['empty']->getError());
		$this->assertSame('one.txt', $result['uploadedFiles']['files'][0]->getClientFilename());

		$this->expectException(InvalidMultipartFormDataException::class);
		$this->expectExceptionCode(400);
		$parser->parse(
			$contentType,
			$body . "--boundary\r\n"
				. "Content-Disposition: form-data; name=\"files[]\"; filename=\"two.txt\"\r\n\r\ntwo\r\n"
				. "--boundary--\r\n",
		);
	}

	#[TestWith(['files[]'])]
	#[TestWith(['files[][image]'])]
	public function testRejectsAnUploadAppendWhenTheArrayIndexIsExhausted(string $name): void
	{
		$maximum = PHP_INT_MAX;
		$body = "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"files[{$maximum}]\"; filename=\"first.txt\"\r\n\r\none\r\n"
			. "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"{$name}\"; filename=\"second.txt\"\r\n\r\ntwo\r\n"
			. "--boundary--\r\n";

		$this->expectException(InvalidMultipartFormDataException::class);
		$this->expectExceptionCode(400);
		(new MultipartFormDataParser())->parse('multipart/form-data; boundary=boundary', $body);
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
		$stream = $file->getStream();
		$this->assertSame('binary', $stream->read(6));
		$source = $stream->getMetadata('uri');
		$this->assertFileExists($source);
		$target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-move-test-' . bin2hex(random_bytes(8)) . '.jpg';

		try {
			$file->moveTo($target);

			$this->assertSame('binary-image-data', file_get_contents($target));
			clearstatcache(true, $source);
			$this->assertFileDoesNotExist($source);
			$this->assertFalse($stream->isReadable());

			$this->expectException(RuntimeException::class);
			$file->moveTo($target);
		} finally {
			if (is_file($target)) {
				unlink($target);
			}
		}
	}

	public function testTemporaryUploadLivesAsLongAsItsStream(): void
	{
		$content = "\x00\xFF\r\n";
		$body = "--boundary\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"bytes.bin\"\r\n\r\n"
			. $content . "\r\n--boundary--\r\n";
		$result = (new MultipartFormDataParser())->parse('multipart/form-data; boundary=boundary', $body);
		$stream = $result['uploadedFiles']['file']->getStream();
		$source = $stream->getMetadata('uri');
		unset($result);

		$this->assertFileExists($source);
		$this->assertSame($content, $stream->getContents());

		unset($stream);
		clearstatcache(true, $source);
		$this->assertFileDoesNotExist($source);
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

		$file = $result['uploadedFiles']['avatar'];
		$this->assertSame(UPLOAD_ERR_NO_FILE, $file->getError());
		$this->assertSame('', $file->getClientFilename());
		$this->assertSame('application/octet-stream', $file->getClientMediaType());
		$this->assertSame(0, $file->getSize());
		$this->expectException(RuntimeException::class);
		$file->getStream();
	}

	public function testIgnoresMalformedInput(): void
	{
		$expected = ['parsedBody' => [], 'uploadedFiles' => []];
		$parser = new MultipartFormDataParser();

		$this->assertSame($expected, $parser->parse('multipart/form-data', 'body without a boundary'));
		$this->assertSame($expected, $parser->parse(
			'multipart/form-data; boundary=boundary',
			"--boundary\r\nInvalid part headers\r\n\r\ncontent\r\n--boundary--\r\n",
		));
	}
}
