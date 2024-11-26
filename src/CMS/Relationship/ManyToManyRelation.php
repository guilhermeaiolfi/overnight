<?php

declare(strict_types=1);

namespace ON\Cms\Relationship;

class ManyToMany extends Relation
{
	public string $type = "m2m";

	public function __construct(
		public ?int $id = null,
		public ?string $left_collection = null,
		public ?string $left_field = null,
		public ?string $junction_collection = null,
		public ?string $right_collection = null,
		public ?string $right_field = null,
	) {

	}
}
