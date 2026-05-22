<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

use Cycle\ORM\Parser\AbstractNode;

interface RelationLoaderInterface extends HandlerInterface
{
	public function configureNode(AbstractNode $parent): AbstractNode;
}
