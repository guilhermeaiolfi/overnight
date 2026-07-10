<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\Manual\Builder;
use ON\Data\ORM\Representation\Schema\Manual\PropertyRef;
use ON\Data\ORM\Session;
use ON\RestApi\Mutation\Payload\DirectusMutation;
use ON\RestApi\Mutation\Payload\PayloadPath;

/**
 * Session-bound mutation ready for hooks, sync, and flush.
 */
final class BoundMutation
{
	/**
	 * @param list<BoundMutation> $related
	 * @param list<PropertyRef> $pendingProperties
	 */
	public function __construct(
		public readonly string $operation,
		public readonly CollectionInterface $collection,
		public readonly object $representation,
		public readonly BoundItemState $state,
		public readonly DirectusMutation $mutation,
		public readonly ?Key $identity = null,
		public readonly PayloadPath $path = new PayloadPath([]),
		public array $related = [],
		public readonly ?RecordState $rootRecord = null,
		public array $pendingProperties = [],
		public ?Builder $projection = null,
	) {
	}

	public function isRoot(): bool
	{
		return $this->path->isRoot();
	}

	public function finalizeProjection(): void
	{
		if ($this->projection === null) {
			return;
		}

		$data = $this->state->getData();
		foreach ($data as $field => $value) {
			$this->representation->{(string) $field} = $value;
		}

		$source = $this->projection->from($this->collection)->tracked();
		$refs = [];
		foreach (array_keys($data) as $fieldName) {
			$name = (string) $fieldName;
			if ($this->collection->hasField($name)) {
				$refs[] = $source->field($name);
			}
		}
		if ($refs === []) {
			$this->projection->properties($source->all())->end();
		} else {
			$this->projection->properties(...$refs)->end();
		}

		$this->pendingProperties = [];
		$this->projection = null;
	}
}
