<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\RestApi\Mutation\MutationNode;
use ON\RestApi\Mutation\MutationQueue;
use ON\RestApi\Mutation\MutationStateInterface;

class ItemUpdating extends ItemCreating
{
	public function __construct(
		MutationNode $node,
		protected string $id,
		MutationQueue $queue,
		array $path = [],
		?CollectionInterface $rootCollection = null,
		?MutationStateInterface $rootState = null
	) {
		parent::__construct($node, $queue, $path, $rootCollection, $rootState);
	}

	public function eventName(): string
	{
		return 'restapi.item.updating';
	}

	public function getId(): string
	{
		return $this->id;
	}
}
