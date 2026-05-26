<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use ON\DB\DatabaseManager;
use ON\Event\EventsExtension;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemDeleting;
use ON\RestApi\Event\ItemUpdating;
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
		protected EventsExtension $events,
		protected DatabaseManager $databaseManager,
		protected ItemRepositoryInterface $items
	) {
	}

	public function register(array $options = []): void
	{
		$this->table = $options['table'] ?? 'revisions';
		$this->collections = $options['collections'] ?? null;

		$this->events->registerListener('restapi.item.creating', [$this, 'onItemCreate']);
		$this->events->registerListener('restapi.item.updating', [$this, 'onItemUpdate']);
		$this->events->registerListener('restapi.item.deleting', [$this, 'onItemDelete']);
	}

	public function onItemCreate(ItemCreating $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$this->writeRevision($collectionName, '', 'create', null, $event->getState()->getData());
	}

	public function onItemUpdate(ItemUpdating $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->items->findByIdentity(
			$event->getCollection(),
			$event->getPrimaryKeyValue(),
		);

		$this->writeRevision($collectionName, $event->getPrimaryKeyValue()->toUrlId(), 'update', $currentItem, $event->getState()->getData());
	}

	public function onItemDelete(ItemDeleting $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->items->findByIdentity(
			$event->getCollection(),
			$event->getPrimaryKeyValue(),
		);

		$this->writeRevision($collectionName, $event->getPrimaryKeyValue()->toUrlId(), 'delete', $currentItem, null);
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
