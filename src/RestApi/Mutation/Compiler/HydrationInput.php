<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Compiler;

use ON\ORM\Definition\Collection\CollectionInterface;

/**
 * Raw hydrator input before the payload has been parsed into the first record node.
 */
final readonly class HydrationInput implements HydrationSubjectInterface
{
	/**
	 * @param array<string, mixed> $input
	 */
	public function __construct(
		public CollectionInterface $collection,
		public array $input,
		public HydrationOptions $options,
	) {
	}
}
