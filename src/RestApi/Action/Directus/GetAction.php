<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\RestApi\Support\RegistrySupportTrait;
use ON\RestApi\Support\DirectusSupportTrait;
use ON\RestApi\Action\RestActionInterface;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Handler\AliasRegistry;
use ON\RestApi\Handler\HandlerFactory;
use ON\RestApi\Query\DirectusQueryBuilder;
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\AuthorizationGuard;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

use function ON\Mapper\map;

final class GetAction implements RestActionInterface
{
	use RegistrySupportTrait;
	use DirectusSupportTrait;

	private const INTERMEDIATE_REPRESENTATION = PhpRepresentation::class;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private RestApiConfig $config,
		private EventDispatcherInterface $eventDispatcher,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + [
			'dispatchEvents' => true,
			'output' => PhpRepresentation::class,
		];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$identity = $collection->getPrimaryKey()->getValue((string) ($params['id'] ?? ''));
		$querySpec = map($payload['query'] ?? [])
			->using(DirectusQueryBuilder::class, $collection)
			->to(QuerySpec::class);

		if (!$options['dispatchEvents']) {
			$item = $this->mapRowOutput(
				$collection,
				$this->get($collection, $identity, $querySpec),
				self::INTERMEDIATE_REPRESENTATION,
				$options['output'],
			);
		} else {
			$event = new ItemGet($collection, $identity, $querySpec, $options);
			$this->eventDispatcher->dispatch($event);
			AuthorizationGuard::assert($event);
			$querySpec = $event->getQuerySpec() ?? $querySpec;
			$responseOptions = $event->getOptions() + ['output' => PhpRepresentation::class];

			if ($event->isDefaultPrevented()) {
				$item = $this->mapRowOutput(
					$collection,
					$event->getResult(),
					self::INTERMEDIATE_REPRESENTATION,
					$responseOptions['output'],
				);
			} else {
				$event->setResult($this->get($collection, $identity, $querySpec));
				$item = $this->mapRowOutput(
					$collection,
					$event->getResult(),
					self::INTERMEDIATE_REPRESENTATION,
					$responseOptions['output'],
				);
			}
		}

		if ($item === null) {
			throw RestApiError::notFound();
		}

		return ['data' => $item];
	}

	private function get(
		CollectionInterface $collection,
		PrimaryKeyValue|string $identity,
		?QuerySpec $querySpec = null,
	): ?array {
		if ($querySpec === null) {
			return $this->items->findByIdentity($collection, $identity);
		}

		$selection = $this->buildSelectionPlan($querySpec->selection);
		$requestedColumnNames = $this->fieldNamesToColumnNames($collection, $selection['requestedFields']);
		$internalRelationKeyColumnNames = $this->getRelationKeyColumnNames($collection, $selection['relations']);
		$fieldsForSelect = array_values(array_unique(array_merge(
			$selection['fields'],
			$this->columnNamesToFieldNames($collection, $internalRelationKeyColumnNames)
		)));

		$query = $this->items->select($collection, $fieldsForSelect);
		PrimaryKeyCriteria::applyWhere($query, $collection, $identity);
		$query->limit(1);
		$row = $this->items->fetchOne($query);
		if ($row === null) {
			return null;
		}

		$items = $this->fetchData(
			$collection,
			[$row],
			$requestedColumnNames === [] && !$querySpec->selection->explicit
				? $this->fieldNamesToColumnNames($collection, $collection->getVisibleFields())
				: $requestedColumnNames,
			$internalRelationKeyColumnNames,
			$selection['relations']
		);

		$item = $items[0] ?? null;

		return $item !== null
			? map($item)
				->using(CollectionRowMapper::class, $collection)
				->from(StorageRepresentation::class)
				->as(self::INTERMEDIATE_REPRESENTATION)
				->toArray()
			: null;
	}

	/**
	 * @param class-string<\ON\Mapper\Representation\RepresentationInterface> $from
	 * @param class-string<\ON\Mapper\Representation\RepresentationInterface> $to
	 */
	private function mapRowOutput(
		CollectionInterface $collection,
		?array $row,
		string $from,
		string $to,
	): ?array {
		if ($row === null) {
			return null;
		}

		return map($row)
			->using(CollectionRowMapper::class, $collection)
			->from($from)
			->as($to)
			->toArray();
	}

	private function fetchData(
		CollectionInterface $collection,
		array $rows,
		array $requestedColumnNames,
		array $internalRelationKeyColumnNames,
		array $relations,
		?AliasRegistry $aliases = null
	): array {
		if ($rows === []) {
			return [];
		}

		$root = $this->relationHandlers->configuredRoot(
			$collection,
			$rows,
			array_keys($rows[0]),
			$requestedColumnNames,
			$internalRelationKeyColumnNames,
			$relations,
			$aliases ?? new AliasRegistry()
		);

		return $root->fetchData();
	}

}
