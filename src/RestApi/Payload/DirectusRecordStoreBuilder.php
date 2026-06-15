<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\Mapper\ConversionGateway;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\RepresentationInterface;
use ON\Mapper\Representation\StorageRepresentation;
use ON\Mapper\Representation\WireRepresentation;
use ON\Mapper\Structural\CollectionRowMapper;
use ON\Mapper\Structural\MapperInterface;
use ON\Mapper\Structural\MappingContext;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Mutation\Compiler\RecordStoreCompiler;
use ON\RestApi\Mutation\Compiler\HydrationOptions;
use ON\RestApi\Mutation\CycleRecordLoader;
use ON\RestApi\Mutation\RecordNode;
use ON\RestApi\Mutation\RecordStore;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Repository\ItemRepositoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function ON\Mapper\map;

/**
 * Directus wire body → compiled RecordStore.
 *
 * parse → hydrate record store → convert field values (from → to representation)
 *
 * @example
 * map($body)
 *     ->using(DirectusRecordStoreBuilder::class, $collection, 'update', $identity, $files)
 *     ->from(WireRepresentation::class)
 *     ->as(PhpRepresentation::class)
 *     ->to(RecordStore::class);
 */
final class DirectusRecordStoreBuilder implements MapperInterface
{
	private RecordStoreCompiler $compiler;

	public function __construct(
		private readonly Registry $registry,
		ItemRepositoryInterface $items,
		\ON\RestApi\Handler\HandlerFactory $handlers,
		CycleRecordLoader $records,
		private readonly ?ConversionGateway $gateway = null,
		?RecordStoreCompiler $compiler = null,
	) {
		$this->compiler = $compiler ?? new RecordStoreCompiler($items, $handlers, $records);
	}

	public static function defaultRepresentations(): array
	{
		return [
			'from' => WireRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public static function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		if (! is_array($from) || $to !== RecordStore::class) {
			return false;
		}

		return self::resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): RecordStore
	{
		$collection = self::resolveCollection($context);
		if ($collection === null) {
			throw new RuntimeException('DirectusRecordStoreBuilder requires a CollectionInterface argument.');
		}

		[$mode, $id, $files] = $this->resolveBuildArgs($context);
		$defaults = self::defaultRepresentations();

		return $this->build(
			$collection,
			$from,
			$mode,
			$id,
			$files,
			$context->sourceRepresentation ?? $defaults['from'] ?? WireRepresentation::class,
			$context->outputRepresentation ?? $defaults['as'] ?? PhpRepresentation::class,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function build(
		CollectionInterface $collection,
		array $input,
		string $mode = 'upsert',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		string $from = WireRepresentation::class,
		string $to = PhpRepresentation::class,
	): RecordStore {
		$store = $this->compiler->compile($collection, $input, new HydrationOptions($mode, $id, $files));
		$store->root = $this->unserializeNode($store->root, $from, $to);

		return $store;
	}

	private function unserializeNode(RecordNode $node, string $from, string $to): RecordNode
	{
		if ($from === $to) {
			return $node;
		}

		$identity = $node->state->getPrimaryKeyValue(false);
		$node->fields = $this->unserializeFields($node->collection, $node->fields, $from, $to);
		$node->syncState();
		if ($node->operation === 'update' && $identity !== null) {
			foreach ($identity->getValues() as $fieldName => $value) {
				if ($value instanceof ValueRef && $value->getState() === $node->state && $value->getField() === $fieldName) {
					continue;
				}

				$node->state->setValue($fieldName, $value);
			}
		}

		foreach ($node->relations as $relation) {
			$this->unserializeRelation($relation, $from, $to);
		}

		return $node;
	}

	private function unserializeRelation(\ON\RestApi\Mutation\RelationNode $relation, string $from, string $to): void
	{
		foreach ($relation->children as $child) {
			if (in_array($child->operation, ['create', 'update', 'delete'], true)) {
				$this->unserializeNode($child, $from, $to);
			}
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function unserializeFields(
		CollectionInterface $collection,
		array $fields,
		string $from,
		string $to,
	): array {
		if ($fields === []) {
			return $fields;
		}

		$wireInput = [];
		foreach ($fields as $name => $value) {
			if ($value instanceof ValueRef || $value instanceof UploadedFileInterface) {
				continue;
			}

			$wireInput[$name] = $value;
		}

		if ($wireInput === []) {
			return $fields;
		}

		$php = map($wireInput, gateway: $this->conversionGateway())
			->using(CollectionRowMapper::class, $collection)
			->from($from)
			->as($to)
			->toArray();

		foreach ($php as $name => $value) {
			$fields[$name] = $value;
		}

		return $fields;
	}

	private function conversionGateway(): ConversionGateway
	{
		return $this->gateway ?? ConversionGateway::get();
	}

	private static function resolveCollection(MappingContext $context): ?CollectionInterface
	{
		if ($context->mapperClass === self::class && isset($context->args[0])) {
			$collection = $context->args[0];
			if ($collection instanceof CollectionInterface) {
				return $collection;
			}
		}

		return null;
	}

	/**
	 * @return array{0: 'create'|'update'|'upsert', 1: PrimaryKeyValue|string|null, 2: array<string, mixed>}
	 */
	private function resolveBuildArgs(MappingContext $context): array
	{
		$mode = $context->args[1] ?? 'upsert';
		if (! in_array($mode, ['create', 'update', 'upsert'], true)) {
			throw new RuntimeException(sprintf(
				'DirectusRecordStoreBuilder mode must be create, update, or upsert; `%s` given.',
				$mode,
			));
		}

		$id = $context->args[2] ?? null;
		if ($id !== null && ! is_string($id) && ! $id instanceof PrimaryKeyValue) {
			throw new RuntimeException('DirectusRecordStoreBuilder identity must be a string, PrimaryKeyValue, or null.');
		}

		$files = $context->args[3] ?? [];
		if (! is_array($files)) {
			throw new RuntimeException('DirectusRecordStoreBuilder files argument must be an array.');
		}

		return [$mode, $id, $files];
	}
}
