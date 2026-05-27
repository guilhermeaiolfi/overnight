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
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Mutation\ValueRef;
use ON\RestApi\Payload\Action\CreateAction;
use ON\RestApi\Payload\Action\RelationAction;
use ON\RestApi\Payload\Action\UpdateAction;
use ON\RestApi\Payload\Node\MutationNodeSpec;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Node\RelationPayload;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use ON\RestApi\Payload\Parser\PayloadParserInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyCriteria;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function ON\Mapper\map;

/**
 * Directus wire body → MutationSpec.
 *
 * parse → normalize → convert field values (from → to representation)
 *
 * @example
 * map($body)
 *     ->using(DirectusMutationBuilder::class, $collection, 'update', $identity, $files)
 *     ->from(WireRepresentation::class)
 *     ->as(PhpRepresentation::class)
 *     ->to(MutationSpec::class);
 */
final class DirectusMutationBuilder implements MapperInterface
{
	private PayloadParserInterface $parser;

	public function __construct(
		private readonly Registry $registry,
		private readonly ItemRepositoryInterface $items,
		private readonly PayloadNormalizer $normalizer,
		private readonly ?ConversionGateway $gateway = null,
		?PayloadParserInterface $parser = null,
	) {
		$this->parser = $parser ?? new DirectusPayloadParser();
	}

	public function defaultRepresentations(): array
	{
		return [
			'from' => WireRepresentation::class,
			'as' => PhpRepresentation::class,
		];
	}

	public function canMap(mixed $from, mixed $to, MappingContext $context): bool
	{
		if ($context->mapperClass !== null && $context->mapperClass !== self::class) {
			return false;
		}

		if (! is_array($from) || $to !== MutationSpec::class) {
			return false;
		}

		return $this->resolveCollection($context) !== null;
	}

	public function map(mixed $from, mixed $to, MappingContext $context): MutationSpec
	{
		$collection = $this->resolveCollection($context);
		if ($collection === null) {
			throw new RuntimeException('DirectusMutationBuilder requires a CollectionInterface argument.');
		}

		[$mode, $id, $files] = $this->resolveBuildArgs($context);
		$defaults = $this->defaultRepresentations();

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
	): MutationSpec {
		$spec = $this->parser->parse($collection, $input, $mode, $id, $files);
		$operation = $this->resolveOperation($mode, $collection, $spec, $id);
		$normalizeState = new MutationState($collection, $spec->root->fields);

		if ($id !== null) {
			$identity = PrimaryKeyCriteria::normalize($collection, $id);
			foreach ($identity->values() as $fieldName => $value) {
				$normalizeState->setValue($fieldName, $value);
			}
		}

		$this->normalizer->normalize(
			$spec,
			new MutationContext(
				$collection,
				$normalizeState,
				$operation,
			)
		);

		return $this->unserializeSpec($spec, $from, $to);
	}

	/**
	 * @param 'create'|'update'|'upsert' $mode
	 */
	private function resolveOperation(
		string $mode,
		CollectionInterface $collection,
		MutationSpec $spec,
		PrimaryKeyValue|string|null $id,
	): string {
		if ($mode !== 'upsert') {
			return $mode;
		}

		$resolvedId = $id ?? $collection->getPrimaryKey()->extractFromInput($spec->root->fields);
		if ($resolvedId === null) {
			return 'create';
		}

		$resolvedId = PrimaryKeyCriteria::normalize($collection, $resolvedId);

		return $this->items->findByIdentity($collection, $resolvedId, StorageRepresentation::class) !== null ? 'update' : 'create';
	}

	private function unserializeSpec(
		MutationSpec $spec,
		string $from = WireRepresentation::class,
		string $to = PhpRepresentation::class,
	): MutationSpec {
		if ($from === $to) {
			return $spec;
		}

		$this->unserializeNode($spec->root, $from, $to);

		return $spec;
	}

	private function unserializeNode(MutationNodeSpec $node, string $from, string $to): void
	{
		$collection = $this->registry->getCollection($node->collection);
		$node->fields = $this->unserializeFields($collection, $node->fields, $from, $to);

		foreach ($node->relations as $relation) {
			$this->unserializeRelation($relation, $from, $to);
		}
	}

	private function unserializeRelation(RelationPayload $relation, string $from, string $to): void
	{
		foreach ($relation->actions as $action) {
			$this->unserializeAction($action, $from, $to);
		}
	}

	private function unserializeAction(RelationAction $action, string $from, string $to): void
	{
		if ($action instanceof CreateAction || $action instanceof UpdateAction) {
			if ($action->node !== null) {
				$this->unserializeNode($action->node, $from, $to);
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

	private function resolveCollection(MappingContext $context): ?CollectionInterface
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
				'DirectusMutationBuilder mode must be create, update, or upsert; `%s` given.',
				$mode,
			));
		}

		$id = $context->args[2] ?? null;
		if ($id !== null && ! is_string($id) && ! $id instanceof PrimaryKeyValue) {
			throw new RuntimeException('DirectusMutationBuilder identity must be a string, PrimaryKeyValue, or null.');
		}

		$files = $context->args[3] ?? [];
		if (! is_array($files)) {
			throw new RuntimeException('DirectusMutationBuilder files argument must be an array.');
		}

		return [$mode, $id, $files];
	}
}
