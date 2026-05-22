<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

class BelongsToLoader extends BelongsToHandler implements RelationLoaderInterface
{
	public function configureNode(\Cycle\ORM\Parser\AbstractNode $parent): \Cycle\ORM\Parser\AbstractNode
	{
		return $this->configureParserNode($parent);
	}
}
