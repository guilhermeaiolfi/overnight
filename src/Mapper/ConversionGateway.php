<?php

declare(strict_types=1);

namespace ON\Mapper;

use ON\Application;
use ON\Container\ContainerExtension;
use ON\Mapper\Conversion\ConversionDirection;
use ON\Mapper\Conversion\EdgeConverterRegistry;
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Field\FieldTypeRegistry;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\RepresentationInterface;
use ON\Mapper\Structural\MapperRegistry;
use ON\Mapper\Structural\MappingContext;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Central conversion service: representations, field types, structural mappers.
 *
 * - to() / convertScalar() for wire ↔ PHP ↔ storage given a known FieldContext
 * - getMappers() for map()->to(Dto::class) style walks
 */
final class ConversionGateway
{
	private static ?self $instance = null;

	private static MapperConfig $config;

	/** @var array<class-string<RepresentationInterface>, RepresentationInterface> */
	private array $representations = [];

	private readonly MapperRegistry $mappers;

	public function __construct(
		private readonly FieldTypeRegistry $fieldTypes = new FieldTypeRegistry(),
		private readonly EdgeConverterRegistry $edges = new EdgeConverterRegistry(),
		?MapperRegistry $mappers = null,
		?ContainerInterface $container = null,
		RepresentationInterface ...$representations,
	) {
		$this->mappers = $mappers ?? MapperRegistry::createDefault($this, $container);

		foreach ($representations as $representation) {
			$this->register($representation);
		}
	}

	public static function configure(MapperConfig $config): void
	{
		self::$config = $config;
		self::$instance = null;
	}

	public static function reset(): void
	{
		self::$instance = null;
		self::$config = new MapperConfig();
	}

	public static function get(): self
	{
		if (self::$instance !== null) {
			return self::$instance;
		}

		$fromContainer = self::tryContainer();
		if ($fromContainer !== null) {
			self::$instance = $fromContainer;

			return self::$instance;
		}

		self::$instance = self::create(self::getConfig());

		return self::$instance;
	}

	public static function setInstance(self $gateway): void
	{
		self::$instance = $gateway;
	}

	public static function createDefault(): self
	{
		return self::create(new MapperConfig());
	}

	public static function create(MapperConfig $config, ?ContainerInterface $container = null): self
	{
		$fieldTypes = new FieldTypeRegistry();
		foreach ($config->fieldTypes as $type => $handler) {
			$fieldTypes->register($type, $handler);
		}

		$edges = new EdgeConverterRegistry();
		foreach ($config->edgeConverters as $converterClass) {
			$edges->register($converterClass);
		}

		$representations = [
			new Representation\StorageRepresentation($fieldTypes),
			new Representation\WireRepresentation($fieldTypes),
			new PhpRepresentation(),
		];

		foreach ($config->representations as $representationClass) {
			$representations[] = new $representationClass($fieldTypes);
		}

		return new self($fieldTypes, $edges, null, $container, ...$representations);
	}

	public function register(RepresentationInterface $representation): void
	{
		$this->representations[$representation::class] = $representation;
	}

	public function getEdges(): EdgeConverterRegistry
	{
		return $this->edges;
	}

	public function getFieldTypes(): FieldTypeRegistry
	{
		return $this->fieldTypes;
	}

	public function getMappers(): MapperRegistry
	{
		return $this->mappers;
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function convertScalar(
		mixed $value,
		FieldContext $field,
		MappingContext $mapping,
		ConversionDirection $direction,
	): mixed {
		if ($value === null) {
			return null;
		}

		$from = $direction === ConversionDirection::Inbound
			? $mapping->sourceRepresentation
			: ($mapping->sourceRepresentation ?? PhpRepresentation::class);
		$to = $direction === ConversionDirection::Inbound
			? ($mapping->outputRepresentation ?? PhpRepresentation::class)
			: $mapping->outputRepresentation;

		if ($from === null || $to === null || $from === $to) {
			return $value;
		}

		return $this->to($from, $value, $to, $field);
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function to(string $from, mixed $value, string $to, FieldContext $field): mixed
	{
		if ($from === $to) {
			return $value;
		}

		if ($value === null) {
			return null;
		}

		$edge = $this->getEdges()->resolve($from, $to, $field);
		if ($edge !== null) {
			return $edge->convert($value, $field);
		}

		$handler = $this->getFieldTypes()->resolve($field);
		if ($handler !== null) {
			return $this->convertWithFieldType($handler, $from, $value, $to, $field);
		}

		if ($from !== PhpRepresentation::class) {
			$value = $this->getRepresentation($from)->toPhp($value, $field);
		}

		if ($to !== PhpRepresentation::class) {
			$value = $this->getRepresentation($to)->fromPhp($value, $field);
		}

		return $value;
	}

	/**
	 * @param class-string<FieldTypeInterface> $handler
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	private function convertWithFieldType(
		string $handler,
		string $from,
		mixed $value,
		string $to,
		FieldContext $field,
	): mixed {
		if ($from === $to) {
			return $value;
		}

		if ($from !== PhpRepresentation::class) {
			$value = $handler::toPhp($from, $value, $field);
		}

		if ($to !== PhpRepresentation::class) {
			$value = $handler::fromPhp($to, $value, $field);
		}

		return $value;
	}

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	private function getRepresentation(string $representation): RepresentationInterface
	{
		if (! isset($this->representations[$representation])) {
			throw UnsupportedConversionException::unregistered($representation);
		}

		return $this->representations[$representation];
	}

	private static function getConfig(): MapperConfig
	{
		return self::$config ??= new MapperConfig();
	}

	private static function tryContainer(): ?self
	{
		$app = Application::$instance;
		if ($app === null) {
			return null;
		}

		try {
			/** @var ContainerExtension|null $containerExtension */
			$containerExtension = $app->ext(ContainerExtension::ID);
			$container = $containerExtension?->getContainer();
			if (! $container instanceof ContainerInterface) {
				return null;
			}

			if (! $container->has(self::class)) {
				return null;
			}

			return $container->get(self::class);
		} catch (Throwable) {
			return null;
		}
	}
}
