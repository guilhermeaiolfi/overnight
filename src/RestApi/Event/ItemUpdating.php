<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

class ItemUpdating extends ItemCreating
{
	public function __construct(
		CollectionInterface $collection,
		MutationStateInterface $state,
		protected PrimaryKeyValue $identity,
		array $path = [],
		?MutationStateInterface $rootState = null,
	) {
		parent::__construct($collection, $state, $path, $rootState);
	}

	public function eventName(): string
	{
		return 'restapi.item.updating';
	}

	public function getPrimaryKeyValue(): PrimaryKeyValue
	{
		return $this->identity;
	}

	public function getId(): PrimaryKeyValue
	{
		return $this->getPrimaryKeyValue();
	}
}
