<?php

declare(strict_types=1);

namespace ON\Init\Attribute;

use Attribute;
use ON\Init\InitStage;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class After extends InitHook
{
	public function stage(): InitStage
	{
		return InitStage::After;
	}
}
