<?php

declare(strict_types=1);

namespace Tests\ON\ORM;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Repository guard: production code must not reintroduce the deleted ON\ORM\Definition stack.
 */
final class LegacyDefinitionAbsenceTest extends TestCase
{
	/** @var list<string> */
	private const FORBIDDEN = [
		'ON\\ORM\\Definition',
		'OrmConfigureEvent',
		'ON\\ORM\\Container\\RegistryFactory',
		'OnDataCycleRegistryGenerator',
		'OnDataCycleRegistryGeneratorFactory',
	];

	public function testProductionSourceHasNoLegacyDefinitionReferences(): void
	{
		$srcRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src';
		$this->assertDirectoryExists($srcRoot);

		$hits = [];

		$iterator = new RegexIterator(
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
			),
			'/\.php$/i',
			RecursiveRegexIterator::MATCH
		);

		/** @var SplFileInfo $file */
		foreach ($iterator as $file) {
			if (! $file->isFile()) {
				continue;
			}

			$contents = file_get_contents($file->getPathname());
			if ($contents === false) {
				continue;
			}

			foreach (self::FORBIDDEN as $needle) {
				if (str_contains($contents, $needle)) {
					$relative = substr($file->getPathname(), strlen($srcRoot) + 1);
					$hits[] = sprintf('%s → %s', $relative, $needle);
				}
			}
		}

		$this->assertSame(
			[],
			$hits,
			"Forbidden legacy definition references found under src/:\n" . implode("\n", $hits)
		);
	}
}
