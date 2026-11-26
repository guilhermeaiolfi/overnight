<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\DB\DatabaseManager;
use ON\RestApi\Event\AuthState;
use ON\RestApi\Event\ItemCreated;
use ON\RestApi\Event\ItemDeleted;
use ON\RestApi\Event\ItemUpdated;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Repository\ItemRepositoryInterface;
use ON\RestApi\RestApiConfig;
use ON\RestApi\Support\PrimaryKey;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tracks create/update/delete operations in a revisions table.
 *
 * Options:
 *   'table'       => 'revisions'  (default)
 *   'collections' => null          (null = track all)
 *
 * Required table schema:
 *   CREATE TABLE revisions (
 *       id INTEGER PRIMARY KEY AUTOINCREMENT,
 *       collection VARCHAR(255) NOT NULL,
 *       item_id VARCHAR(255) NOT NULL,
 *       action VARCHAR(20) NOT NULL,
 *       data TEXT,
 *       delta TEXT,
 *       created_at DATETIME NOT NULL
 *   );
 */
class RevisionAddon implements RestApiAddonInterface
{
	private const PREVIOUS_ROW = self::class . '.previousRow';

	protected string $table = 'revisions';
	protected ?array $collections = null;

	public function __construct(
		protected Registry $registry,
		protected DatabaseManager $databaseManager,
		protected ItemRepositoryInterface $items,
		protected ?LoggerInterface $logger = null,
		protected ?RestApiConfig $config = null,
	) {
	}

	public function register(array $options = []): void
	{
		$table = $options['table'] ?? 'revisions';
		if (! is_string($table) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table) !== 1) {
			throw new InvalidArgumentException('Revision table must be a single SQL identifier.');
		}

		$this->table = $table;
		$this->collections = $options['collections'] ?? null;

		foreach ($this->registry->getCollections() as $collection) {
			if ($this->shouldTrack($collection->getName())) {
				$this->registerCollectionHooks($collection);
			}
		}
	}

	public function onItemCreate(ItemCreated $event): void
	{
		$collection = $event->getCollection();
		$state = $event->getState();
		$this->record($collection, $state->getKey(), 'create', null, $state->getRow() ?? $state->getData());
	}

	public function capturePreviousRow(ItemUpdating $event): void
	{
		if ($event->getAuthState() !== AuthState::Allowed || $event->isDefaultPrevented()) {
			return;
		}

		$state = $event->getState();
		$row = $state->getRow() ?? $this->items->findByIdentity($event->getCollection(), $event->getKey());
		$state->setMetadata(self::PREVIOUS_ROW, $this->publicFields($event->getCollection(), $row));
	}

	public function onItemUpdate(ItemUpdated $event): void
	{
		$collection = $event->getCollection();
		$state = $event->getState();
		$previous = $state->getMetadata(self::PREVIOUS_ROW);
		$current = $this->publicFields($collection, $state->getRow() ?? $state->getData());
		$delta = array_filter(
			$current ?? [],
			fn (mixed $value, string $field): bool => ! array_key_exists($field, $previous ?? [])
				|| ! $this->sameRevisionValue($value, $previous[$field]),
			ARRAY_FILTER_USE_BOTH,
		);
		$this->record($collection, $state->getKey(), 'update', $previous, $delta);
	}

	public function onItemDelete(ItemDeleted $event): void
	{
		if ($event->getResult()) {
			$this->record($event->getCollection(), $event->getKey(), 'delete', $event->getRow(), null);
		}
	}

	private function sameRevisionValue(mixed $current, mixed $previous): bool
	{
		if (is_object($current) && is_object($previous)) {
			return $current == $previous;
		}

		return $current === $previous;
	}

	protected function registerCollectionHooks(CollectionInterface $collection): void
	{
		RestHooks::for($collection)
			->on('create.after', [$this, 'onItemCreate'])
			->on('update.before', [$this, 'capturePreviousRow'], PHP_INT_MIN)
			->on('update.after', [$this, 'onItemUpdate'])
			->on('delete.after', [$this, 'onItemDelete']);
	}

	private function record(
		CollectionInterface $collection,
		?Key $key,
		string $action,
		?array $data,
		?array $delta,
	): void {
		if ($key === null || ! $this->shouldTrack($collection->getName())) {
			return;
		}

		$this->writeRevision(
			$collection->getName(),
			PrimaryKey::of($collection)->toUrlId($key),
			$action,
			$this->publicFields($collection, $data),
			$this->publicFields($collection, $delta),
		);
	}

	private function publicFields(CollectionInterface $collection, ?array $row): ?array
	{
		if ($row === null) {
			return null;
		}

		$result = [];
		foreach ($collection->getFields() as $name => $field) {
			if (! $field->isHidden() && array_key_exists($name, $row)) {
				$result[$name] = $row[$name];
			}
		}

		return $result;
	}

	protected function shouldTrack(string $collectionName): bool
	{
		if ($collectionName === $this->table) {
			return false;
		}

		if ($this->collections === null) {
			return true;
		}

		return in_array($collectionName, $this->collections, true);
	}

	protected function writeRevision(
		string $collectionName,
		string $itemId,
		string $action,
		?array $data,
		?array $delta
	): void {
		try {
			$connection = $this->databaseManager->getDatabaseConnection($this->config?->get('database', 'cycle'));
			$values = [
				'collection' => $collectionName,
				'item_id' => $itemId,
				'action' => $action,
				'data' => $data !== null ? json_encode($data, JSON_THROW_ON_ERROR) : null,
				'delta' => $delta !== null ? json_encode($delta, JSON_THROW_ON_ERROR) : null,
				'created_at' => date('Y-m-d H:i:s'),
			];

			if ($connection instanceof CycleDatabaseManager) {
				$connection->database($this->config?->get('cycleDatabase', 'default'))
					->insert($this->table)
					->values($values)
					->run();

				return;
			}
			if (! $connection instanceof PDO) {
				throw new RuntimeException('The revision database has no supported connection.');
			}

			$quote = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "`" : '"';
			$table = $quote . $this->table . $quote;
			$columns = implode(', ', array_map(static fn (string $column): string => $quote . $column . $quote, array_keys($values)));
			$statement = $connection->prepare("INSERT INTO {$table} ({$columns}) VALUES (?, ?, ?, ?, ?, ?)");
			$statement->execute(array_values($values));
		} catch (Throwable $error) {
			$this->logger?->error('Unable to write REST API revision.', [
				'collection' => $collectionName,
				'action' => $action,
				'exception' => $error,
			]);
		}
	}
}
