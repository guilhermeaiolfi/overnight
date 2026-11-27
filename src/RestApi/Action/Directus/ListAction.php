<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\DataRuntime;
use ON\Data\Definition\Registry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Query\Condition\ConditionTag;
use ON\Data\Query\SelectQuery;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Event\ItemList;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\RegistrySupportTrait;

final class ListAction implements RestActionInterface
{
	use RegistrySupportTrait;
	private const REQUEST_CONDITION_TAG = 'restapi.request';

	private QueryParserInterface $parser;

	public function __construct(
		private Registry $registry,
		private DataRuntime $runtime,
		private RestApiConfig $config,
		private RestHookDispatcher $hooks,
		?QueryParserInterface $parser = null,
	) {
		$this->parser = $parser ?? new DirectusQueryParser($runtime);
	}

	public function __invoke(array $params, mixed $payload = null, ?array $options = null): mixed
	{
		$payload = is_array($payload) ? $payload : [];
		$options = ($options ?? []) + [
			'dispatchEvents' => true,
			'output' => PhpRepresentation::class,
		];
		$collection = $this->getCollectionOrThrow($this->registry, (string) ($params['collection'] ?? ''));
		$queryParams = is_array($payload['query'] ?? null) ? $payload['query'] : [];
		$context = $this->createContext();
		$query = $this->parser->parse($collection, $queryParams, $context);

		if ($context->isAggregate()) {
			if (! $options['dispatchEvents']) {
				return ['data' => $this->aggregate($query, $context)];
			}

			$event = new ItemList($collection, $query, $context, $options);
			$this->hooks->dispatch($collection, 'list', $event);
			$query = $event->getQuery();
			$context = $event->getContext();

			if ($event->isDefaultPrevented()) {
				return ['data' => $event->getResult() ?? []];
			}

			$result = $this->aggregate($query, $context);
			$responseOptions = $event->getOptions() + ['output' => $options['output']];
			$event->setResult($result);

			return [
				'data' => map($result)
					->args($collection)
					->from(PhpRepresentation::class)
					->as($responseOptions['output'])
					->collection()
					->to([]),
			];
		}

		if ($this->parser instanceof DirectusQueryParser && ($options['dispatchEvents'] || in_array('total_count', $context->getMeta(), true))) {
			$this->tagRequestConditions($query);
		}

		$event = null;
		$responseOptions = null;
		if ($options['dispatchEvents']) {
			$event = new ItemList($collection, $query, $context, $options);
			$this->hooks->dispatch($collection, 'list', $event);
			$query = $event->getQuery();
			$context = $event->getContext();
			$responseOptions = $event->getOptions() + ['output' => $options['output']];

			if ($event->isDefaultPrevented()) {
				$response = ['data' => $event->getResult() ?? []];
				if ($event->getTotalCount() !== null) {
					$response['meta'] = ['filter_count' => $event->getTotalCount()];
				}

				return $response;
			}
		}

		$result = $this->list($query, $context);
		if (isset($event)) {
			$event->setResult($result['data'] ?? [], $result['meta']['filter_count'] ?? null);
		}

		$output = ($responseOptions ?? $options)['output'];
		$rows = $result['data'] ?? [];
		if ($context->hasNativeRelationAliases() && $output !== PhpRepresentation::class) {
			$schema = $query->getFetchSchema();
			$rows = array_map(static fn (array $row): array => RelationAliases::mapNative($row, $schema, $output), $rows);
		}
		$result['data'] = map($rows)
			->args($collection)
			->from(PhpRepresentation::class)
			->as($output)
			->collection()
			->to([]);
		$aliases = $context->getRelationResponseNames();
		if (! $context->hasNativeRelationAliases() && $aliases !== []) {
			$result['data'] = array_map(static fn (array $row): array => RelationAliases::apply($row, $aliases), $result['data']);
		}

		if (($result['meta'] ?? []) === []) {
			unset($result['meta']);
		}

		return $result;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
	 */
	private function list(SelectQuery $query, QueryContext $context): array
	{
		$meta = [];
		if (in_array('total_count', $context->getMeta(), true)) {
			$meta['total_count'] = $this->countAll($query);
		}

		if (in_array('filter_count', $context->getMeta(), true)) {
			$meta['filter_count'] = $this->countFiltered($query);
		}

		return [
			'data' => $query->fetchAll(),
			'meta' => $meta,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function aggregate(SelectQuery $query, QueryContext $context): array
	{
		$rows = $query->fetchAll();
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($context->getGroupBy() as $group) {
				$alias = $group['alias'];
				if (array_key_exists($alias, $row)) {
					$entry[$group['responseName']] = $row[$alias];
				}
			}

			foreach ($context->getAggregates() as $aggregate) {
				$alias = $aggregate['alias'];
				if (array_key_exists($alias, $row)) {
					$entry[$aggregate['function']][$aggregate['field']] = $row[$alias];
				}
			}

			$result[] = $entry;
		}

		return $result;
	}

	private function tagRequestConditions(SelectQuery $query): void
	{
		$conditions = $query->getConditionList();
		$items = $conditions->getItems();
		$conditions->clear();
		foreach ($items as $item) {
			$tags = $item->getTags();
			if ($tags === [ConditionTag::USER]) {
				$tags[] = self::REQUEST_CONDITION_TAG;
			}
			$conditions->add($item->getCondition(), ...$tags);
		}
	}

	private function countAll(SelectQuery $query): int
	{
		$unfiltered = $query->copy();
		$unfiltered->getConditionList()->removeByTag(self::REQUEST_CONDITION_TAG);

		return $unfiltered->count();
	}

	private function countFiltered(SelectQuery $query): int
	{
		return $query->count();
	}

	private function createContext(): QueryContext
	{
		return QueryContext::fromConfig($this->config);
	}
}
