<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Event;

use ON\RestApi\Mutation\BoundMutation;

/**
 * Ordered before/after event descriptors derived from Directus-bound mutations.
 *
 * Before: parent then children (pre-order) so nested intent is part of the parent request.
 * After: children then parent (post-order) so parent final events run after nested work.
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
		$before = [];
		self::preOrder($root, $before);
		$after = [];
		self::postOrder($root, $after);

		return new self($before, $after);
	}

	/**
	 * @param list<BoundMutation> $ordered
	 */
	private static function preOrder(BoundMutation $mutation, array &$ordered): void
	{
		$ordered[] = $mutation;
		foreach ($mutation->related as $related) {
			self::preOrder($related, $ordered);
		}
	}

	/**
	 * @param list<BoundMutation> $ordered
	 */
	private static function postOrder(BoundMutation $mutation, array &$ordered): void
	{
		foreach ($mutation->related as $related) {
			self::postOrder($related, $ordered);
		}
		$ordered[] = $mutation;
	}
}
