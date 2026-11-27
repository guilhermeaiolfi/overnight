<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use DateTimeImmutable;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Mapping;
use ON\DataIntegration\Container\DataRuntimeFactory;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Mutation\DirectusMutationBinder;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Mutation\SessionFactory;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class MutationReloadTest extends TestCase
{
	use RestApiTestFixtures;

	protected function tearDown(): void
	{
		Mapping::resetDefaultGateway();
	}

	#[DataProvider('sharedRowFields')]
	public function testBatchHooksReloadASharedNestedRow(bool $withMappedDate, int $expectedReloads): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$pdo = DataRuntimeFactory::connectionFromCycleDatabase($db->database())->nativeDriver();
		if ($withMappedDate) {
			$registry->getCollection('tag')->field('created_on', 'date')->end();
			$pdo->exec("ALTER TABLE tag ADD COLUMN created_on TEXT DEFAULT '2026-01-01'");
		}
		$pdo->exec('CREATE TRIGGER normalize_tag_name AFTER UPDATE OF name ON tag '
			. 'BEGIN UPDATE tag SET name = upper(NEW.name) WHERE id = NEW.id; END');

		$trace = new class () {
			/** @var list<string> */
			public array $statements = [];
		};
		$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MutationTraceStatement::class, [$trace]]);

		$items = $this->createItems($registry, $db);
		$runtime = $this->runtimeForItems($items);
		$sessions = new SessionFactory($runtime);
		$coordinator = new MutationCoordinator(
			$sessions,
			new DirectusMutationBinder($items, $runtime, $sessions),
			new DirectusPayloadParser(),
			$items,
			$this->noopHookDispatcher($registry),
			$runtime,
		);

		$seen = [];
		$dates = [];
		RestHooks::for($registry->getCollection('tag'))
			->on('update.after', static function (ItemUpdated $event) use (&$seen, &$dates): void {
				$row = $event->getRow();
				$seen[] = [
					$event->getRootState()->getKey()?->getValue(),
					$event->getPathString(),
					$row['id'] ?? null,
					$row['name'] ?? null,
				];
				$dates[] = $row['created_on'] ?? null;
				$event->getState()->setValue('name', 'hook-local edit');
			});

		$results = $coordinator->batchUpdate($registry->getCollection('post'), [
			['identity' => '1', 'input' => ['tags' => ['update' => [['id' => 1, 'name' => 'Batch name']]]]],
			['identity' => '2', 'input' => ['tags' => [1]]],
		]);

		$this->assertSame([1, 2], array_column($results, 'id'));
		$this->assertSame([
			['1', 'tags.update.0', 1, 'BATCH NAME'],
			['2', 'tags.0', 1, 'BATCH NAME'],
		], $seen);
		if ($withMappedDate) {
			$this->assertInstanceOf(DateTimeImmutable::class, $dates[0]);
			$this->assertEquals($dates[0], $dates[1]);
			$this->assertNotSame($dates[0], $dates[1]);
		}

		$wroteTag = false;
		$tagReloads = 0;
		foreach ($trace->statements as $sql) {
			if (str_starts_with($sql, 'UPDATE "tag" ')) {
				$wroteTag = true;
			}
			if ($wroteTag && preg_match('/^SELECT .* FROM "tag" /s', $sql)) {
				++$tagReloads;
			}
		}
		$this->assertTrue($wroteTag);
		$this->assertSame($expectedReloads, $tagReloads);
	}

	public static function sharedRowFields(): iterable
	{
		yield 'scalar fields share one read' => [false, 1];
		yield 'mapped date values stay independent' => [true, 2];
	}
}

final class MutationTraceStatement extends PDOStatement
{
	protected function __construct(private readonly object $trace)
	{
	}

	public function execute(?array $params = null): bool
	{
		$this->trace->statements[] = $this->queryString;

		return parent::execute($params);
	}
}
