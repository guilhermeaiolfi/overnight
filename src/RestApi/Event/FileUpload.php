<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Event\HasEventNameInterface;
use Psr\Http\Message\UploadedFileInterface;

class FileUpload implements HasEventNameInterface
{
	private mixed $storedValue = null;

	public function __construct(
		protected CollectionInterface $collection,
		protected string $fieldName,
		protected UploadedFileInterface $file
	) {
	}

	public function eventName(): string
	{
		return 'restapi.file.upload';
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getFile(): UploadedFileInterface
	{
		return $this->file;
	}

	public function setFile(UploadedFileInterface $file): void
	{
		$this->file = $file;
	}

	public function getStoredPath(): ?string
	{
		return is_string($this->storedValue) ? $this->storedValue : null;
	}

	public function setStoredPath(string $path): void
	{
		$this->storedValue = $path;
	}

	public function getStoredValue(): mixed
	{
		return $this->storedValue;
	}

	public function setStoredValue(mixed $value): void
	{
		$this->storedValue = $value;
	}
}
