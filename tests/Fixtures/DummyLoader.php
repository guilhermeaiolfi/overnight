<?php

declare(strict_types=1);

namespace Tests\ON\Fixtures;

use ON\ORM\Select\Traits\ColumnsTrait;

class DummyLoader
{
	use ColumnsTrait;

	protected function getAlias(): string
	{
		return "";
	}
}
