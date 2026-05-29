<?php

declare(strict_types=1);

namespace ON\Middleware\BodyParams;

use Mezzio\Helper\BodyParams\StrategyInterface;
use ON\Http\MultipartFormDataParser;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses multipart/form-data bodies when the SAPI did not populate them.
 *
 * POST requests are handled by PHP superglobals. PATCH/PUT/DELETE payloads that
 * still use multipart/form-data are parsed manually when parsedBody is empty.
 */
final class MultipartFormDataStrategy implements StrategyInterface
{
	public function __construct(
		private readonly MultipartFormDataParser $parser = new MultipartFormDataParser(),
	) {
	}

	public function match(string $contentType): bool
	{
		return 1 === preg_match('#^multipart/form-data($|[ ;])#i', $contentType);
	}

	public function parse(ServerRequestInterface $request): ServerRequestInterface
	{
		$parsedBody = $request->getParsedBody();
		$uploadedFiles = $request->getUploadedFiles();

		if (
			is_array($parsedBody) && $parsedBody !== []
			&& is_array($uploadedFiles) && $uploadedFiles !== []
		) {
			return $request;
		}

		$rawBody = (string) $request->getBody();
		if ($rawBody === '') {
			return $request;
		}

		$result = $this->parser->parse($request->getHeaderLine('Content-Type'), $rawBody);

		return $request
			->withParsedBody(
				is_array($parsedBody) && $parsedBody !== []
					? $parsedBody
					: $result['parsedBody']
			)
			->withUploadedFiles(
				is_array($uploadedFiles) && $uploadedFiles !== []
					? $uploadedFiles
					: $result['uploadedFiles']
			);
	}
}
