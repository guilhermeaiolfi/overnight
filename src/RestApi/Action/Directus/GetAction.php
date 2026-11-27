<?php

declare(strict_types=1);

namespace ON\RestApi\Action\Directus;

use ON\Data\DataRuntime;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use ON\RestApi\Action\RestActionInterface;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemGet;
use ON\RestApi\Hook\RestHookDispatcher;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use ON\RestApi\Query\Parser\QueryParserInterface;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\PrimaryKey;
use ON\RestApi\Support\RegistrySupportTrait;

final class GetAction implements RestActionInterface
{
	use RegistrySupportTrait;

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
		$identity = PrimaryKey::of($collection)->parseUrlId((string) ($params['id'] ?? ''));
		$queryParams = is_array($payload['query'] ?? null) ? $payload['query'] : [];
		$context = $this->createContext();
		$query = $this->parser->parse($collection, $queryParams, $context);
		$this->applyIdentity($query, $collection, $identity);
		$query->limit(1);

		$output = $options['output'];
		$manualResult = false;
		if ($options['dispatchEvents']) {
			$event = new ItemGet($collection, $identity, $query, $context, $options);
			$this->hooks->dispatch($collection, 'get', $event);
			$query = $event->getQuery();
			$context = $event->getContext();
			$responseOptions = $event->getOptions() + ['output' => PhpRepresentation::class];
			$output = $responseOptions['output'];
			$manualResult = $event->isDefaultPrevented();
			if (! $manualResult) {
				$event->setResult($query->fetchOne());
			}
			$item = $event->getResult();
		} else {
			$item = $query->fetchOne();
		}

		if ($item === null) {
			throw RestApiError::notFound();
		}

		$nativeAliases = $context->hasNativeRelationAliases();
		$schema = $nativeAliases ? $query->getFetchSchema() : null;
		if ($schema !== null) {
			$item = RelationAliases::mapNative($item, $schema, $output);
		}
		$item = map($item)->args($collection)->from(PhpRepresentation::class)->as($output)->to([]);
		if (! $nativeAliases || ($manualResult && $schema === null)) {
			$item = RelationAliases::apply($item, $context->getRelationResponseNames(), preserveExisting: $nativeAliases);
		}

		return ['data' => $item];
	}

	private function applyIdentity(
		SelectQuery $query,
		CollectionInterface $collection,
		Key $identity,
	): void {
		foreach (PrimaryKey::of($collection)->getFields() as $field) {
			$query->where(x()->eq($query->field($field->getName()), $identity->getFieldValue($field->getName())));
		}
	}

	private function createContext(): QueryContext
	{
		return QueryContext::fromConfig($this->config);
	}
}
