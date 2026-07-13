<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemUpdating extends ItemCreating
{
	public function __construct(
		CollectionInterface $collection,
		MutationStateInterface $state,
		protected Key $identity,
		array $path = [],
		?MutationStateInterface $rootState = null,
	) {
		parent::__construct($collection, $state, $path, $rootState);
	}

	public function eventName(): string
	{
		return 'restapi.item.updating';
	}

	public function getKey(): Key
	{
		return $this->identity;
	}

	/** @deprecated Use getKey() */
	public function getPrimaryKeyValue(): Key
	{
		return $this->getKey();
	}

	public function getId(): Key
	{
		return $this->getKey();
	}
}
