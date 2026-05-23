<?php

declare(strict_types=1);

namespace ON\RestApi\Resolver;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Typecast\CollectionTypecast;
use ON\RestApi\Query\Node\FilterNode;
use ON\RestApi\Resolver\Sql\SqlDataSource;

final class TypecastDataSource implements DataSourceInterface
{
	public function __construct(
		private readonly SqlDataSource $inner,
		private readonly CollectionTypecast $typecast,
	) {
	}

	public function transaction(callable $callback): mixed
	{
		return $this->inner->transaction($callback);
	}

	public function create(CollectionInterface $collection, array $input): ?array
	{
		return $this->inner->create(
			$collection,
			$this->typecast->fromPhp($collection, $input)
		);
	}

	public function update(CollectionInterface $collection, FilterNode $criteria, array $input): ?array
	{
		return $this->inner->update(
			$collection,
			$criteria,
			$this->typecast->fromPhp($collection, $input, partial: true)
		);
	}

	public function delete(CollectionInterface $collection, FilterNode $criteria): bool
	{
		return $this->inner->delete($collection, $criteria);
	}

	public function clearCache(): void
	{
		$this->inner->clearCache();
	}

	public function inner(): SqlDataSource
	{
		return $this->inner;
	}
}
