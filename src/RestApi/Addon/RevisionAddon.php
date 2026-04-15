<?php

declare(strict_types=1);

namespace ON\RestApi\Addon;

use ON\DB\DatabaseManager;
use ON\Event\EventsExtension;
use ON\ORM\Definition\Registry;
use ON\RestApi\Event\ItemCreate;
use ON\RestApi\Event\ItemDelete;
use ON\RestApi\Event\ItemUpdate;
use ON\RestApi\Resolver\RestResolverInterface;

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
		protected Registry $registry,
		protected DatabaseManager $databaseManager,
		protected ?RestResolverInterface $resolver = null
	) {
	}

	public function register(array $options = []): void
	{
		$this->table = $options['table'] ?? 'revisions';
		$this->collections = $options['collections'] ?? null;

		$this->events->registerListener('restapi.item.create', [$this, 'onItemCreate']);
		$this->events->registerListener('restapi.item.update', [$this, 'onItemUpdate']);
		$this->events->registerListener('restapi.item.delete', [$this, 'onItemDelete']);
	}

	public function onItemCreate(ItemCreate $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$this->writeRevision($collectionName, '', 'create', null, $event->getInput());
	}

	public function onItemUpdate(ItemUpdate $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->resolver?->get($event->getCollection(), $event->getId());

		$this->writeRevision($collectionName, $event->getId(), 'update', $currentItem, $event->getInput());
	}

	public function onItemDelete(ItemDelete $event): void
	{
		$collectionName = $event->getCollection()->getName();

		if (!$this->shouldTrack($collectionName)) {
			return;
		}

		$currentItem = $this->resolver?->get($event->getCollection(), $event->getId());

		$this->writeRevision($collectionName, $event->getId(), 'delete', $currentItem, null);
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
