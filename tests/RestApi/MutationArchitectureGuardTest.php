<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Prevents the legacy RestApi mutation queue/command engine from returning.
 */
final class MutationArchitectureGuardTest extends TestCase
{
	/** @var list<string> */
	private const FORBIDDEN_FQCN_FRAGMENTS = [
		'ON\\RestApi\\Mutation\\MutationQueue',
		'ON\\RestApi\\Mutation\\MutationNode',
		'ON\\RestApi\\Mutation\\RelationNode',
		'ON\\RestApi\\Mutation\\MutationState;',
		'ON\\RestApi\\Mutation\\MutationState ',
		'ON\\RestApi\\Mutation\\ValueRef',
		'ON\\RestApi\\Mutation\\InsertCommand',
		'ON\\RestApi\\Mutation\\UpdateCommand',
		'ON\\RestApi\\Mutation\\DeleteCommand',
		'ON\\RestApi\\Mutation\\AbstractMutationCommand',
		'ON\\RestApi\\Payload\\Node\\MutationNodeSpec',
		'ON\\RestApi\\Payload\\Node\\MutationSpec',
		'ON\\RestApi\\Payload\\DirectusMutationBuilder',
		'ON\\RestApi\\Handler\\Mutation\\ManyToManyApply',
		'ON\\RestApi\\Handler\\Mutation\\BelongsToApply',
		'ON\\RestApi\\Handler\\Mutation\\ForeignKeyOnTargetApply',
		'CycleRecordCommitter',
		'RecordCommitterInterface',
		'PivotInsertCommand',
		'PivotDeleteCommand',
		'OperationQueue',
	];

	/** @var list<string> */
	private const FORBIDDEN_BARE_SYMBOLS = [
		'MutationQueue',
		'MutationNodeSpec',
		'getThroughCollection',
		'getThroughInnerKey',
		'getThroughOuterKey',
	];

	public function testProductionRestApiCodeDoesNotReferenceLegacyMutationEngine(): void
	{
		$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RestApi';
		$violations = [];

		foreach ($this->phpFiles($root) as $file) {
			$relative = substr($file, strlen(dirname(__DIR__, 2)) + 1);
			$contents = file_get_contents($file);
			self::assertNotFalse($contents);

			foreach (self::FORBIDDEN_FQCN_FRAGMENTS as $symbol) {
				if (str_contains($contents, $symbol)) {
					$violations[] = sprintf('%s references %s', $relative, $symbol);
				}
			}

			foreach (self::FORBIDDEN_BARE_SYMBOLS as $symbol) {
				if (preg_match('/\b' . preg_quote($symbol, '/') . '\b/', $contents) === 1) {
					$violations[] = sprintf('%s references %s', $relative, $symbol);
				}
			}

			if (
				str_contains($relative, 'DirectusMutationBinder.php')
				|| str_contains($relative, 'RelationBaselineReader.php')
			) {
				foreach (['M2MRelation', 'getThrough(', 'instanceof \\ON\\Data\\Definition\\Relation\\M2MRelation'] as $leak) {
					if (str_contains($contents, $leak)) {
						$violations[] = sprintf('%s leaks storage detail via %s', $relative, $leak);
					}
				}
			}
		}

		self::assertSame([], $violations, "Legacy mutation symbols found:\n" . implode("\n", $violations));
	}

	public function testLegacyMutationEngineFilesAreGone(): void
	{
		$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'RestApi';
		$forbiddenPaths = [
			'Mutation/MutationQueue.php',
			'Mutation/MutationNode.php',
			'Mutation/ValueRef.php',
			'Mutation/InsertCommand.php',
			'Handler/HandlerFactory.php',
			'Payload/DirectusMutationBuilder.php',
			'Payload/Parser/DirectusPayloadParser.php',
		];

		$existing = [];
		foreach ($forbiddenPaths as $path) {
			if (is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
				$existing[] = $path;
			}
		}

		self::assertSame([], $existing, 'Legacy mutation files still present: ' . implode(', ', $existing));
	}

	/**
	 * @return iterable<string>
	 */
	private function phpFiles(string $root): iterable
	{
		$iterator = new RegexIterator(
			new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
			'/\.php$/',
		);

		foreach ($iterator as $file) {
			yield $file->getPathname();
		}
	}
}
