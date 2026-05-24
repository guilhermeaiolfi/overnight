<?php

declare(strict_types=1);

namespace ON\RestApi\Payload;

use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Collection\PrimaryKeyValue;
use ON\ORM\Definition\Registry;
use ON\RestApi\Mutation\MutationState;
use ON\RestApi\Payload\Node\MutationSpec;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use ON\RestApi\Payload\Parser\PayloadParserInterface;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\Support\PrimaryKeyCriteria;

/**
 * Directus wire-format entry pipeline — symmetric with QueryParser + QueryNormalizer.
 *
 * parse → normalize → unserialize (PHP domain values)
 */
final class DirectusMutationBuilder
{
	private PayloadParserInterface $parser;

	public function __construct(
		private readonly Registry $registry,
		private readonly ItemRepositoryInterface $items,
		private readonly PayloadNormalizer $normalizer,
		private readonly MutationSpecUnserializer $unserializer,
		?PayloadParserInterface $parser = null,
	) {
		$this->parser = $parser ?? new DirectusPayloadParser();
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 * @param 'create'|'update'|'upsert' $mode
	 * @param bool $unserializeWire When true (default), convert wire-format scalars to PHP domain values.
	 *                              Set false when the input is already built with PHP types.
	 */
	public function build(
		CollectionInterface $collection,
		array $input,
		string $mode = 'upsert',
		PrimaryKeyValue|string|null $id = null,
		array $files = [],
		bool $unserializeWire = true,
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

		if (! $unserializeWire) {
			return $spec;
		}

		return $this->unserializer->unserialize($spec, $operation === 'update');
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

		return $this->items->findByIdentity($collection, $resolvedId, typed: false) !== null ? 'update' : 'create';
	}
}
