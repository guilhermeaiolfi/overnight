<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use ON\DB\DatabaseManager;
use ON\ORM\Definition\Collection\CollectionInterface;
use ON\ORM\Definition\Registry;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Repository\ItemRepositoryInterface;

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
	protected string $table = 'revisions';
	protected ?array $collections = null;

	public function __construct(
		protected Registry $registry,
		protected DatabaseManager $databaseManager,
		protected ItemRepositoryInterface $items
	) {
	}

	public function register(array $options = []): void
	{
		$this->table = $options['table'] ?? 'revisions';
		$this->collections = $options['collections'] ?? null;

		foreach ($this->registry->getCollections() as $collection) {
			if ($this->shouldTrack($collection->getName())) {
				$this->registerCollectionHooks($collection);
			}
		}
	}

	public function onItemCreate(ItemCreating $event): void
	{
		$collectionName = $event->getCollection()->getName();
		$state = $event->getState();

		if ($state === null || !$this->shouldTrack($collectionName)) {
			return;
		}

		$this->writeRevision($collectionName, '', 'create', null, $state->getData());
	}

	public function onItemUpdate(ItemUpdating $event): void
	{
		$collectionName = $event->getCollection()->getName();
		$identity = $event->getPrimaryKeyValue();
		$state = $event->getState();

		if ($identity === null || $state === null || !$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->items->findByIdentity(
			$event->getCollection(),
			$identity,
		);

		$this->writeRevision($collectionName, $identity->toUrlId(), 'update', $currentItem, $state->getData());
	}

	public function onItemDelete(ItemDeleting $event): void
	{
		$collectionName = $event->getCollection()->getName();
		$identity = $event->getPrimaryKeyValue();

		if ($identity === null || !$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->items->findByIdentity(
			$event->getCollection(),
			$identity,
		);

		$this->writeRevision($collectionName, $identity->toUrlId(), 'delete', $currentItem, null);
	}

	protected function registerCollectionHooks(CollectionInterface $collection): void
	{
		RestHooks::for($collection)
			->on(ItemCreating::class, [self::class, 'onItemCreate'])
			->on(ItemUpdating::class, [self::class, 'onItemUpdate'])
			->on(ItemDeleting::class, [self::class, 'onItemDelete']);
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
			$database = $this->databaseManager->getDatabase();
			if ($database === null) {
				return;
			}

			$connection = $database->getConnection();
			$sql = "INSERT INTO `{$this->table}` (`collection`, `item_id`, `action`, `data`, `delta`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)";
			$stmt = $connection->prepare($sql);
			$stmt->execute([
				$collectionName,
				$itemId,
				$action,
				$data !== null ? json_encode($data) : null,
				$delta !== null ? json_encode($delta) : null,
				date('Y-m-d H:i:s'),
			]);
		} catch (\Throwable) {
			// Silently fail — revision tracking should not break the main operation
		}
	}
}
