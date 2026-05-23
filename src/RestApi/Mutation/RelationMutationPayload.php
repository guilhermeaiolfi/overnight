<?php

declare(strict_types=1);

namespace ON\RestApi\Mutation;

use ON\ORM\Definition\Collection\PrimaryKeyValue;

final class RelationMutationPayload
{
	/** @var list<ChildIntent> */
	public array $create = [];

	/** @var list<ChildIntent> */
	public array $update = [];

	/** @var list<ChildIntent> */
	public array $delete = [];

	/** @var list<LinkIntent|PrimaryKeyValue|int|string> */
	public array $connect = [];

	/** @var list<LinkIntent|PrimaryKeyValue|int|string> */
	public array $disconnect = [];

	public static function empty(): self
	{
		return new self();
	}

	public function hasCreate(): bool
	{
		return $this->create !== [];
	}

	public function hasUpdate(): bool
	{
		return $this->update !== [];
	}

	public function hasDelete(): bool
	{
		return $this->delete !== [];
	}

	public function hasConnect(): bool
	{
		return $this->connect !== [];
	}

	public function hasDisconnect(): bool
	{
		return $this->disconnect !== [];
	}
}
