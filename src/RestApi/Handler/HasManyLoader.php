<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

class HasManyLoader extends HasManyHandler implements RelationLoaderInterface
{
	public function configureNode(\Cycle\ORM\Parser\AbstractNode $parent): \Cycle\ORM\Parser\AbstractNode
	{
		return $this->configureParserNode($parent);
	}
}
