<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver\Sql\Loader;

use Cycle\ORM\Parser\AbstractNode;

interface RelationLoaderInterface extends LoaderInterface
{
	public function configureNode(AbstractNode $parent, string $name): AbstractNode;
}
