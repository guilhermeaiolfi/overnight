<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Mutation\Compiler\HydrationOptions;
use ON\RestApi\Mutation\Compiler\HydrationInput;
use ON\RestApi\Mutation\CycleRecordCommitter;
use ON\RestApi\Mutation\Compiler\Pass\MergeMutationInput;
use ON\RestApi\Mutation\Compiler\Pass\ParseDirectusPayload;
use ON\RestApi\Payload\Parser\DirectusPayloadParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class MutationCompilerPassTest extends TestCase
{
	use RestApiTestFixtures;

	public function testMergeMutationInputFeedsParserWithMergedFiles(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$post = $registry->getCollection('post');
		$titleFile = $this->createUploadedFile('title.txt');
		$authorFile = $this->createUploadedFile('author.txt');

		$input = new HydrationInput(
			$post,
			[
				'comments' => [
					['body' => 'First comment'],
				],
			],
			new HydrationOptions(files: [
				'title' => $titleFile,
				'comments' => [
					0 => ['author' => $authorFile],
				],
			]),
		);

		$merged = (new MergeMutationInput())->run($input);
		$this->assertInstanceOf(HydrationInput::class, $merged);
		$this->assertSame($titleFile, $merged->input['title']);
		$this->assertSame($authorFile, $merged->input['comments'][0]['author']);

		$parsed = (new ParseDirectusPayload(new DirectusPayloadParser()))->run($merged);
		$this->assertSame($titleFile, $parsed->fields['title']);
		$this->assertArrayHasKey('comments', $parsed->relations);
		$this->assertSame($authorFile, $parsed->relations['comments']->children[0]->relationData['author']);
	}

	public function testDeleteActionsAlsoCompileChildNodes(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$items = $this->createItems($registry, $this->createFullDatabase());

		$node = $this->m($registry, $items, 'user', [
			'posts' => [
				['id' => 1, 'title' => 'Keep this one'],
			],
		], 'update', '1');

		$posts = $node->relations['posts'];
		$this->assertCount(2, $posts->children);
		$this->assertSame('omitted', $posts->children[1]->relationIntent);
		$this->assertSame('delete', $posts->children[1]->operation);
		$this->assertSame(['id' => 2], $posts->children[1]->fields);
		$deleteChildren = $posts->childRecordsByOperation('delete');
		$this->assertCount(1, $deleteChildren);
		$this->assertSame($posts->children[1], $deleteChildren[0]);
	}

	public function testHydrationKeepsCycleCurrentRecordAlongsideCurrentData(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$root = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			[
				'posts' => [
					['id' => 1, 'title' => 'Updated title'],
				],
			],
			'update',
			'1',
		)->root;

		$this->assertNotNull($root->currentData);
		$this->assertInstanceOf(\stdClass::class, $root->currentRecord);
		$this->assertSame(1, $root->currentRecord->id ?? null);

		$postNode = $root->relations['posts']->childRecordsByOperation('update')[0] ?? null;
		$this->assertNotNull($postNode);
		$this->assertNotNull($postNode->currentData);
		$this->assertInstanceOf(\stdClass::class, $postNode->currentRecord);
		$this->assertSame(1, $postNode->currentRecord->id ?? null);
		$rootPosts = $root->currentRecord->posts->fetch();
		$this->assertSame(is_array($rootPosts) ? ($rootPosts[0] ?? null) : null, $postNode->currentRecord);
		$this->assertInstanceOf(\stdClass::class, $root->record);
		$this->assertSame('John', $root->record->name ?? null);
		$this->assertInstanceOf(\stdClass::class, $postNode->record);
		$this->assertSame('Updated title', $postNode->record->title ?? null);
	}

	public function testHydrationBuildsWorkingCycleRecordForCreates(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$root = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			[
				'name' => 'New User',
				'posts' => [
					['title' => 'First post', 'content' => 'hello'],
				],
			],
			'create',
		)->root;

		$this->assertInstanceOf(\stdClass::class, $root->record);
		$this->assertSame('New User', $root->record->name ?? null);

		$postNode = $root->relations['posts']->childRecordsByOperation('create')[0] ?? null;
		$this->assertNotNull($postNode);
		$this->assertInstanceOf(\stdClass::class, $postNode->record);
		$this->assertSame('First post', $postNode->record->title ?? null);
	}

	public function testRecordNodeDetectsScalarChangesFromCycleWorkingObjects(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$unchanged = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			['name' => 'John'],
			'update',
			'1',
		)->root;
		$this->assertFalse($unchanged->hasScalarChanges());

		$changed = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			['name' => 'Johnny'],
			'update',
			'1',
		)->root;
		$this->assertTrue($changed->hasScalarChanges());
	}

	public function testHydrationAppliesDesiredHasManyGraphToWorkingCycleRecord(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$root = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			[
				'posts' => [
					['id' => 1, 'title' => 'Updated title'],
				],
			],
			'update',
			'1',
		)->root;

		$this->assertIsArray($root->record->posts ?? null);
		$this->assertCount(1, $root->record->posts);
		$this->assertSame(1, $root->record->posts[0]->id ?? null);
		$this->assertSame('Updated title', $root->record->posts[0]->title ?? null);
	}

	public function testHydrationAppliesDesiredBelongsToGraphToWorkingCycleRecord(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$root = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			[
				'author' => 2,
			],
			'update',
			'1',
		)->root;

		$this->assertInstanceOf(\stdClass::class, $root->record->author ?? null);
		$this->assertSame(2, $root->record->author->id ?? null);
	}

	public function testCycleCommitterPersistsSimpleRootCreateThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			[
				'name' => 'Cycle User',
			],
			'create',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame('Cycle User', $result['name'] ?? null);
		$createdUserId = $result['id'] ?? null;
		$this->assertIsInt($createdUserId);
	}

	public function testCycleCommitterPersistsSimpleRootUpdateThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			['name' => 'Cycle Rename'],
			'update',
			'1',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame(1, $result['id'] ?? null);
		$updated = $items->findByIdentity($registry->getCollection('user'), '1');
		$this->assertSame('Cycle Rename', $updated['name'] ?? null);
	}

	public function testCycleCommitterPersistsSimpleRootDeleteThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = new \ON\RestApi\Mutation\RecordStore(new \ON\RestApi\Mutation\RecordNode(
			collection: $registry->getCollection('user'),
			operation: 'delete',
			state: new \ON\RestApi\Mutation\NodeState($registry->getCollection('user'), ['id' => 3]),
		));

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame(3, $result['id'] ?? null);
		$this->assertNull($items->findByIdentity($registry->getCollection('user'), '3'));
	}

	public function testCycleCommitterPersistsNestedHasManyCreateThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('user'),
			[
				'name' => 'Nested User',
				'posts' => [
					['title' => 'Nested post', 'content' => 'hello'],
				],
			],
			'create',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame('Nested User', $result['name'] ?? null);
		$createdUserId = $result['id'] ?? null;
		$this->assertIsInt($createdUserId);
		$posts = $items->fetchAll(
			$items->select('post', ['id', 'user_id', 'title', 'content'])
				->where('user_id', $createdUserId)
		);
		$this->assertCount(1, $posts);
		$this->assertSame('Nested post', $posts[0]['title'] ?? null);
	}

	public function testCycleCommitterPersistsBelongsToConnectThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			['author' => 2],
			'update',
			'1',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame(1, $result['id'] ?? null);
		$updated = $items->findByIdentity($registry->getCollection('post'), '1');
		$this->assertSame(2, $updated['user_id'] ?? null);
	}

	public function testCycleCommitterPersistsManyToManyCreateThroughRowsPath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			[
				'user_id' => 1,
				'title' => 'Cycle M2M',
				'content' => 'hello',
				'status' => 'published',
				'tags' => [
					'create' => [
						['tag_id' => 1],
						['tag_id' => 2],
					],
				],
			],
			'create',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame('Cycle M2M', $result['title'] ?? null);
		$stmt = $db->database()->query(
			'SELECT tag_id FROM post_tag WHERE post_id = ? ORDER BY tag_id',
			[$result['id']]
		);
		$tagIds = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();

		$this->assertSame([1, 2], $tagIds);
	}

	public function testCycleCommitterPersistsManyToManyInlineCreateThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			[
				'user_id' => 1,
				'title' => 'Cycle Inline Tags',
				'content' => 'hello',
				'status' => 'published',
				'tags' => [
					['name' => 'InlineA'],
					['name' => 'InlineB'],
				],
			],
			'create',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$stmt = $db->database()->query(
			'SELECT t.name FROM post_tag pt INNER JOIN tag t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.name',
			[$result['id']]
		);
		$names = array_column($stmt->fetchAll(), 'name');
		$stmt->close();

		$this->assertSame(['InlineA', 'InlineB'], $names);
	}

	public function testCycleCommitterPersistsManyToManyRemovalByOmissionThroughCyclePath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			['tags' => [['tag_id' => 2]]],
			'update',
			'1',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame(1, $result['id'] ?? null);
		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$tagIds = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();

		$this->assertSame([2], $tagIds);
	}

	public function testCycleCommitterPersistsManyToManyDeleteAsJunctionRemoval(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$records = $this->createCycleRecordLoader($registry, $db);

		$store = $this->createRecordStoreBuilder($registry, $items, null, $records)->build(
			$registry->getCollection('post'),
			['tags' => ['delete' => [1]]],
			'update',
			'1',
		);

		$result = (new CycleRecordCommitter($items, $records, $this->noopHookDispatcher($registry)))->commit($store);

		$this->assertSame(1, $result['id'] ?? null);
		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$tagIds = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();
		$this->assertSame([2], $tagIds);
		$this->assertNotNull($items->findByIdentity($registry->getCollection('tag'), '1'));
	}


	private function createUploadedFile(string $filename): UploadedFileInterface
	{
		return new class($filename) implements UploadedFileInterface {
			public function __construct(private readonly string $filename)
			{
			}

			public function getStream(): StreamInterface
			{
				throw new \BadMethodCallException('Not needed in this test.');
			}

			public function moveTo($targetPath): void
			{
				throw new \BadMethodCallException('Not needed in this test.');
			}

			public function getSize(): ?int
			{
				return null;
			}

			public function getError(): int
			{
				return \UPLOAD_ERR_OK;
			}

			public function getClientFilename(): ?string
			{
				return $this->filename;
			}

			public function getClientMediaType(): ?string
			{
				return 'text/plain';
			}
		};
	}
}
