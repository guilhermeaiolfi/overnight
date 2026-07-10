<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;
use ON\RestApi\Support\PrimaryKeyValue;

class ItemUpdating extends ItemCreating
{
	public function __construct(
		MutationNode $node,
		protected PrimaryKeyValue $identity,
		MutationQueue $queue,
		array $path = [],
		?MutationStateInterface $rootState = null
	) {
		parent::__construct($node, $queue, $path, $rootState);
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
