<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

use ON\Mapper\Field\FieldContext;
use ON\Mapper\Representation\RepresentationInterface;

final class EdgeConverterRegistry
{
	/** @var array<string, class-string<EdgeConverterInterface>> */
	private array $edges = [];

	/** @var array<class-string<EdgeConverterInterface>, EdgeConverterInterface> */
	private array $instances = [];

	/** @param class-string<EdgeConverterInterface> $converter */
	public function register(string $converter): void
	{
		[$from, $to] = $converter::edge();
		$this->edges[$this->key($from, $to)] = $converter;
		$this->edges[$this->key($to, $from)] = $converter;
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function resolve(string $from, string $to, FieldContext $field): ?EdgeConverterInterface
	{
		if ($from === $to) {
			return null;
		}

		$converterClass = $this->edges[$this->key($from, $to)] ?? null;
		if ($converterClass === null || ! $converterClass::supports($field)) {
			return null;
		}

		return $this->instances[$converterClass] ??= new $converterClass();
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	private function key(string $from, string $to): string
	{
		return $from . '->' . $to;
	}
}
