<?php

declare(strict_types=1);

namespace ON\GraphQL\Type;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Represents a file upload. The value is a PSR-7 UploadedFileInterface
 * injected by the multipart request middleware.
 */
class UploadType extends ScalarType
{
	public string $name = 'Upload';
	public ?string $description = 'A file upload. Use multipart/form-data requests to send files.';

	public function serialize(mixed $value): never
	{
		throw new Error('Upload scalar cannot be serialized. It is input-only.');
	}

	public function parseValue(mixed $value): UploadedFileInterface
	{
		if (!$value instanceof UploadedFileInterface) {
			throw new Error('Upload scalar expects a PSR-7 UploadedFileInterface.');
		}

		return $value;
	}

	public function parseLiteral(Node $valueNode, ?array $variables = null): never
	{
		throw new Error('Upload scalar cannot be used in a query literal. Use variables instead.');
	}
}
