<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler;

use ON\ORM\Definition\Collection\PrimaryKeyValue;

/**
 * Carries compile mode, root identity, and uploaded files through the pipeline.
 */
final readonly class HydrationOptions
{
	/**
	 * @param 'create'|'update'|'upsert'|'delete' $mode
	 * @param array<string, mixed> $files
	 */
	public function __construct(
		public string $mode = 'upsert',
		public PrimaryKeyValue|string|null $id = null,
		public array $files = [],
	) {
		if (!in_array($this->mode, ['create', 'update', 'upsert', 'delete'], true)) {
			throw new \InvalidArgumentException(sprintf(
				'Mutation compiler mode must be create, update, upsert, or delete; `%s` given.',
				$this->mode,
			));
		}
	}
}
