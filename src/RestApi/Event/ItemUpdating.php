<?php

declare(strict_types=1);

namespace ON\RestApi\Event;

use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\OperationQueue;
use ON\RestApi\Mutation\NodeStateInterface;

class ItemUpdating extends ItemCreating
{
	public function __construct(
		RecordNode $node,
		protected PrimaryKeyValue $identity,
		OperationQueue $queue,
		array $path = [],
		?NodeStateInterface $rootState = null
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
