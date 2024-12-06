<?php

declare(strict_types=1);

namespace ON\CMS\Definition\Relation;

class O2ORelation extends AbstractRelation
{
	public bool $exclusive = false;

	public function exclusive(bool $exclusive): self
	{
		$this->exclusive = $exclusive;

		return $this;
	}

	public function isExclusive(): bool
	{
		return $this->exclusive;
	}
}
