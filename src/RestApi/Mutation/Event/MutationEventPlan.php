<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Event;

use ON\RestApi\Mutation\BoundMutation;

/**
 * Ordered before/after event descriptors derived from Directus-bound mutations.
 */
final class MutationEventPlan
{
	/**
	 * @param list<BoundMutation> $before
	 * @param list<BoundMutation> $after
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
	) {
	}

	public static function fromBound(BoundMutation $root): self
	{
		$ordered = [];
		self::walk($root, $ordered);

		return new self($ordered, $ordered);
	}

	/**
	 * @param list<BoundMutation> $ordered
	 */
	private static function walk(BoundMutation $mutation, array &$ordered): void
	{
		$ordered[] = $mutation;
		foreach ($mutation->related as $related) {
			self::walk($related, $ordered);
		}
	}
}
