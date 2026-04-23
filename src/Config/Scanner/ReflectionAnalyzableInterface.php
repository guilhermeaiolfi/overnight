<?php

declare(strict_types=1);

namespace ON\Config\Scanner;

interface ReflectionAnalyzableInterface
{
	/** @param array<Reflector> $reflectors */
	public function __analyzeReflection(array $reflectors): void;
}
