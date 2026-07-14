<?php

declare(strict_types=1);

namespace Tests\ON\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Guard: production code must not reintroduce removed ON\ORM / ON\CMS stacks.
 */
final class RemovedOrmCmsAbsenceTest extends TestCase
{
	/** @var list<string> */
	private const FORBIDDEN = [
		'namespace ON\\ORM',
		'namespace ON\\CMS',
		'ON\\ORM\\ORMExtension',
		'ON\\CMS\\CMSExtension',
		'ON\\ORM\\Select',
		'ON\\ORM\\Factory',
		'ON\\ORM\\Definition',
		'ON\\CMS\\DataHandler',
		'ON\\CMS\\Parser',
		'CmsQueryParser',
		'OrmConfigureEvent',
		'ON\\ORM\\Container\\RegistryFactory',
		'OnDataCycleRegistryGenerator',
		'OnDataCycleRegistryGeneratorFactory',
	];

	public function testProductionSourceHasNoRemovedOrmOrCmsReferences(): void
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
			"Forbidden ORM/CMS references found under src/:\n" . implode("\n", $hits)
		);
	}
}
