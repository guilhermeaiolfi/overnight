<?php

declare(strict_types=1);

namespace ON\RestApi\Handler;

class LoaderRegistry extends HandlerRegistry
{
	public static function defaults(): self
	{
		return (new self())
			->default('hasOne', HasOneLoader::class)
			->default('belongsTo', BelongsToLoader::class)
			->default('hasMany', HasManyLoader::class)
			->default('manyToMany', ManyToManyLoader::class);
	}
}
