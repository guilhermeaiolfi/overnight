<?php

declare(strict_types=1);

namespace ON\Mapper;

use ON\Application;
use ON\Container\ContainerExtension;
use ON\Mapper\Conversion\EdgeConverterInterface;
use ON\Mapper\Conversion\EdgeConverterRegistry;
use ON\Mapper\Exception\UnsupportedConversionException;
use ON\Mapper\Field\FieldContext;
use ON\Mapper\Field\FieldMapping;
use ON\Mapper\Field\FieldTypeInterface;
use ON\Mapper\Field\FieldTypeRegistry;
use ON\Mapper\Representation\PhpRepresentation;
use ON\Mapper\Representation\RepresentationInterface;
use ON\Mapper\Structural\MapperRegistry;
use Psr\Container\ContainerInterface;

final class ConversionGateway
{
	private static ?self $instance = null;

	private static MapperConfig $config;

	/** @var array<class-string<RepresentationInterface>, RepresentationInterface> */
	private array $representations = [];

	private readonly MapperRegistry $structuralMappers;

	public function __construct(
		private readonly FieldTypeRegistry $fieldTypes = new FieldTypeRegistry(),
		private readonly EdgeConverterRegistry $edges = new EdgeConverterRegistry(),
		?MapperRegistry $structuralMappers = null,
		RepresentationInterface ...$representations,
	) {
		$this->structuralMappers = $structuralMappers ?? MapperRegistry::createDefault($this);

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

		self::$instance = self::create(self::config());

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

	public static function create(MapperConfig $config): self
	{
		$fieldTypes = new FieldTypeRegistry();
		foreach ($config->fieldTypes as $type => $handler) {
			$fieldTypes->register($type, $handler);
		}

		$edges = new EdgeConverterRegistry();
		foreach ($config->edgeConverters as $converterClass) {
			$edges->register(self::instantiateEdgeConverter($converterClass));
		}

		$representations = [
			new Representation\StorageRepresentation($fieldTypes),
			new Representation\WireRepresentation($fieldTypes),
			new PhpRepresentation(),
		];

		foreach ($config->representations as $representationClass) {
			$representations[] = new $representationClass($fieldTypes);
		}

		return new self($fieldTypes, $edges, null, ...$representations);
	}

	/**
	 * @param class-string<EdgeConverterInterface> $converterClass
	 */
	private static function instantiateEdgeConverter(string $converterClass): EdgeConverterInterface
	{
		return new $converterClass();
	}

	public function register(RepresentationInterface $representation): void
	{
		$this->representations[$representation::class] = $representation;
	}

	public function edges(): EdgeConverterRegistry
	{
		return $this->edges;
	}

	public function fieldTypes(): FieldTypeRegistry
	{
		return $this->fieldTypes;
	}

	public function structuralMappers(): MapperRegistry
	{
		return $this->structuralMappers;
	}

	public function map(FieldContext $field): FieldMapping
	{
		return new FieldMapping($this, $field);
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

		$edge = $this->edges->resolve($from, $to, $field);
		if ($edge !== null) {
			return $edge->convert($value, $field);
		}

		$handler = $this->fieldTypes->resolve($field);
		if ($handler !== null) {
			return $this->convertWithFieldType($handler, $from, $value, $to, $field);
		}

		if ($from !== PhpRepresentation::class) {
			$value = $this->representation($from)->toPhp($value, $field);
		}

		if ($to !== PhpRepresentation::class) {
			$value = $this->representation($to)->fromPhp($value, $field);
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
	private function representation(string $representation): RepresentationInterface
	{
		if (! isset($this->representations[$representation])) {
			throw UnsupportedConversionException::unregistered($representation);
		}

		return $this->representations[$representation];
	}

	private static function config(): MapperConfig
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
		} catch (\Throwable) {
			return null;
		}
	}
}
