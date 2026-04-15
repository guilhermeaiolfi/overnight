<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use League\Event\HasEventName;
use ON\Event\PreventableEventInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use Psr\Http\Message\UploadedFileInterface;

class FileUpload implements HasEventName, PreventableEventInterface
{
	private bool $defaultPrevented = false;
	private ?string $storedPath = null;

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
		return $this->storedPath;
	}

	public function setStoredPath(string $path): void
	{
		$this->storedPath = $path;
	}

	public function preventDefault(): void
	{
		$this->defaultPrevented = true;
	}

	public function isDefaultPrevented(): bool
	{
		return $this->defaultPrevented;
	}
}
