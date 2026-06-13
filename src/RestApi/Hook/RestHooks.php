<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

use ON\ORM\Definition\Collection\CollectionInterface;

final class RestHooks
{
	public const METADATA_KEY = 'restapi::hooks';

	private function __construct(
		private CollectionInterface $collection,
	) {}

	public static function for(CollectionInterface $collection): self
	{
		return new self($collection);
	}

	public function on(string $eventClass, mixed $handler, int $priority = 0): self
	{
		$hooks = $this->collection->metadata(self::METADATA_KEY) ?? [];
		$hooks[ltrim($eventClass, '\\')][] = [
			'handler' => $handler,
			'priority' => $priority,
		];

		$this->collection->metadata(self::METADATA_KEY, $hooks);

		return $this;
	}
}
