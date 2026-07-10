<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation\Payload;

use InvalidArgumentException;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;

/**
 * Normalized to-one Directus payload: null | identity | object create/update.
 */
final readonly class ToOneMutation implements RelationMutation
{
	private function __construct(
		private RelationInterface $relation,
		private PayloadPath $path,
		public ToOneKind $kind,
		public ?Key $identity = null,
		public ?RelatedItemInput $item = null,
	) {
	}

	public static function clear(RelationInterface $relation, PayloadPath $path): self
	{
		return new self($relation, $path, ToOneKind::Clear);
	}

	public static function existing(RelationInterface $relation, PayloadPath $path, Key $identity): self
	{
		return new self($relation, $path, ToOneKind::Existing, identity: $identity);
	}

	public static function item(RelationInterface $relation, PayloadPath $path, RelatedItemInput $item): self
	{
		$kind = $item->isNew() ? ToOneKind::New : ToOneKind::Existing;

		return new self($relation, $path, $kind, identity: $item->identity, item: $item);
	}

	public function relation(): RelationInterface
	{
		return $this->relation;
	}

	public function path(): PayloadPath
	{
		return $this->path;
	}

	public function assertValid(): void
	{
		match ($this->kind) {
			ToOneKind::Clear => null,
			ToOneKind::Existing => $this->identity !== null || $this->item !== null
				? null
				: throw new InvalidArgumentException('Existing to-one mutation requires an identity.'),
			ToOneKind::New => $this->item !== null && $this->item->isNew()
				? null
				: throw new InvalidArgumentException('New to-one mutation requires a new related item.'),
		};
	}
}
