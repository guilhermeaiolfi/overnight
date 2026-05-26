<?php

declare(strict_types=1);

namespace ON\Mapper;

use InvalidArgumentException;
use ON\Config\Config;
use ON\Mapper\Conversion\EdgeConverterInterface;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Representation\RepresentationInterface;

final class MapperConfig extends Config
{
	/** @var array<string, class-string<FieldTypeInterface>> */
	public array $fieldTypes = [];

	/** @var list<class-string<EdgeConverterInterface>> */
	public array $edgeConverters = [];

	/** @var list<class-string<RepresentationInterface>> */
	public array $representations = [];

	/**
	 * Register a mapper component into the appropriate bucket.
	 *
	 * Field handlers always require an explicit type key — never infer from
	 * `storageType()`, which describes DB encoding (e.g. `'string'`) rather than
	 * the ORM field type or PHP class being mapped.
	 *
	 * - `register('file', FileFieldType::class)` — ORM builtin/custom type name
	 * - `register(StatusEnum::class, StatusEnumFieldType::class)` — PHP class-specific handler
	 * - `register(MyEdgeConverter::class)` — edge converter
	 * - `register(MyRepresentation::class)` — representation
	 *
	 * @param non-empty-string $registration
	 * @param class-string<FieldTypeInterface>|null $handler
	 */
	public function register(string $registration, ?string $handler = null): self
	{
		if ($handler !== null) {
			if (! is_subclass_of($handler, FieldTypeInterface::class)) {
				throw new InvalidArgumentException(sprintf(
					'Handler `%s` must implement %s.',
					$handler,
					FieldTypeInterface::class,
				));
			}

			$this->fieldTypes[$registration] = $handler;

			return $this;
		}

		if (is_subclass_of($registration, FieldTypeInterface::class)) {
			throw new InvalidArgumentException(sprintf(
				'Field type handler `%s` must be registered with an explicit type key. '
				. 'Use register($type, %s::class) — do not rely on storageType(), '
				. 'which describes DB encoding and may collide with builtins such as `string`.',
				$registration,
				$registration,
			));
		}

		if (is_subclass_of($registration, EdgeConverterInterface::class)) {
			$this->edgeConverters[] = $registration;

			return $this;
		}

		if (is_subclass_of($registration, RepresentationInterface::class)) {
			$this->representations[] = $registration;

			return $this;
		}

		throw new InvalidArgumentException(sprintf(
			'Registration `%s` must implement %s, %s, or %s.',
			$registration,
			FieldTypeInterface::class,
			EdgeConverterInterface::class,
			RepresentationInterface::class,
		));
	}
}
