<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\RestApi\Support\FormatOutputTrait;
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
use ON\RestApi\Query\Node\QuerySpec;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\QueryNormalizer;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\AuthorizationGuard;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\EventDispatcher\EventDispatcherInterface;

final class GetAction implements RestActionInterface
{
	use FormatOutputTrait;
	use RegistrySupportTrait;
	use DirectusSupportTrait;

	public function __construct(
		private Registry $registry,
		private ItemRepositoryInterface $items,
		private HandlerFactory $relationHandlers,
		private DirectusQueryParser $queryParser,
		private QueryNormalizer $queryNormalizer,
		private RestApiConfig $config,
		private ?EventDispatcherInterface $eventDispatcher = null,
	) {}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$identity = $collection->getPrimaryKey()->getValue((string) ($params['id'] ?? ''));
		$query = $payload['query'] ?? [];
		$querySpec = $query instanceof QuerySpec
			? $this->queryNormalizer->normalize($query)
			: $this->queryNormalizer->normalize($this->queryParser->parse($collection, is_array($query) ? $query : []));
		$options = ($options ?? []) + ['serialize' => true, 'dispatchEvents' => true];

		if (!$options['dispatchEvents']) {
			$item = $this->formatResponseRow($collection, $this->get($collection, $identity, $querySpec), $options);
		} else {
			$event = new ItemGet($collection, $identity, $querySpec, $options);
			$this->eventDispatcher?->dispatch($event);
			if ($this->eventDispatcher !== null) {
				AuthorizationGuard::assert($event);
			}
			$querySpec = $event->getQuerySpec() ?? $querySpec;
			$responseOptions = $event->getOptions();

			if ($event->isDefaultPrevented()) {
				$item = $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
			} else {
				$event->setResult($this->get($collection, $identity, $querySpec));
				$item = $this->formatResponseRow($collection, $event->getResult(), $responseOptions);
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
			return $this->items->findByIdentity($collection, $identity, typed: false);
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

		return $items[0] ?? null;
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
