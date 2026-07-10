<?php

declare(strict_types=1);

namespace Tests\ON\Middleware;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use ON\Middleware\BodyParams\MultipartFormDataStrategy;
use PHPUnit\Framework\TestCase;

final class MultipartFormDataStrategyTest extends TestCase
{
	public function testParsesMultipartPatchRequestsWhenParsedBodyIsEmpty(): void
	{
		$boundary = 'patch-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="data"',
			'',
			'{"title":"Updated title"}',
			'--' . $boundary . '--',
			'',
		]);

		$stream = fopen('php://temp', 'wb+');
		fwrite($stream, $body);
		rewind($stream);

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
		))->withBody(new Stream($stream));

		$parsed = (new MultipartFormDataStrategy())->parse($request);

		$this->assertSame('{"title":"Updated title"}', $parsed->getParsedBody()['data']);
		$this->assertSame([], $parsed->getUploadedFiles());
	}

	public function testLeavesPostRequestsWithParsedBodyAndUploadedFilesUntouched(): void
	{
		$request = (new ServerRequest(method: 'POST'))
			->withParsedBody(['data' => '{"title":"Existing"}'])
			->withUploadedFiles(['file_id' => new UploadedFile(
				fopen('php://temp', 'wb+'),
				0,
				UPLOAD_ERR_OK,
				'test.jpg',
				'image/jpeg'
			)]);

		$parsed = (new MultipartFormDataStrategy())->parse($request);

		$this->assertSame(['data' => '{"title":"Existing"}'], $parsed->getParsedBody());
		$this->assertNotSame([], $parsed->getUploadedFiles());
	}

	public function testParsesMultipartPatchFilesWhenParsedBodyIsAlreadyPopulated(): void
	{
		$boundary = 'patch-boundary';
		$body = implode("\r\n", [
			'--' . $boundary,
			'Content-Disposition: form-data; name="data"',
			'',
			'{"images":{"create":[{"sequence":0}]}}',
			'--' . $boundary,
			'Content-Disposition: form-data; name="images[create][0][file_id]"; filename="photo.jpg"',
			'Content-Type: image/jpeg',
			'',
			'binary-image-data',
			'--' . $boundary . '--',
			'',
		]);

		$stream = fopen('php://temp', 'wb+');
		fwrite($stream, $body);
		rewind($stream);

		$request = (new ServerRequest(
			uri: '/items/news_article/1',
			method: 'PATCH',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
		))
			->withBody(new Stream($stream))
			->withParsedBody(['data' => '{"images":{"create":[{"sequence":0}]}}']);

		$parsed = (new MultipartFormDataStrategy())->parse($request);

		$this->assertSame('{"images":{"create":[{"sequence":0}]}}', $parsed->getParsedBody()['data']);
		$this->assertArrayHasKey('file_id', $parsed->getUploadedFiles()['images']['create'][0]);
	}
}
