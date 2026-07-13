<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Event\ItemCreating;
use ON\RestApi\Event\ItemUpdating;
use ON\RestApi\Hook\RestHooks;
use ON\RestApi\Mutation\DirectusMutationBinder;
use ON\RestApi\Mutation\MutationCoordinator;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Mutation\SessionFactory;
use ON\RestApi\Repository\ItemRepositoryInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\CycleSqliteTestDatabase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class MutationHardeningTest extends TestCase
{
	use RestApiTestFixtures;

	public function testMissingToOneScalarTargetFailsBeforeFlush(): void
	{
		[$service, $db] = $this->ops();
		$postsBefore = $this->countRows($db, 'post');

		try {
			$service->create('post', [
				'user_id' => 1,
				'title' => 'X',
				'content' => 'Y',
				'status' => 'draft',
				'author' => 999,
			], ['dispatchEvents' => false]);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
			$this->assertSame('author', $error->getField());
			$this->assertArrayHasKey('author', $error->getValidationErrors());
		}

		$this->assertSame($postsBefore, $this->countRows($db, 'post'));
	}

	public function testMissingImplicitExistingItemFails(): void
	{
		[$service] = $this->ops();

		try {
			$service->create('post', [
				'user_id' => 1,
				'title' => 'X',
				'content' => 'Y',
				'status' => 'draft',
				'tags' => [999],
			], ['dispatchEvents' => false]);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
			$this->assertSame('tags.0', $error->getField());
		}
	}

	public function testMissingImplicitExistingItemWithUpdatesCreatesRelated(): void
	{
		[$service, $db] = $this->ops();

		$result = $service->create('post', [
			'user_id' => 1,
			'title' => 'X',
			'content' => 'Y',
			'status' => 'draft',
			'tags' => [['id' => 999, 'name' => 'Nope']],
		], ['dispatchEvents' => false]);

		$this->assertGreaterThan(0, (int) ($result['id'] ?? 0));
		$tag = $db->database()->query('SELECT id, name FROM tag WHERE id = 999')->fetch();
		$this->assertNotFalse($tag);
		$this->assertSame('Nope', $tag['name']);
		$link = $db->database()->query('SELECT post_id, tag_id FROM post_tag WHERE tag_id = 999')->fetch();
		$this->assertNotFalse($link);
		$this->assertSame((int) $result['id'], (int) $link['post_id']);
	}

	public function testMissingToOneObjectTargetCreatesRelated(): void
	{
		[$service, $db] = $this->ops();

		$result = $service->create('post', [
			'title' => 'X',
			'content' => 'Y',
			'status' => 'draft',
			'author' => ['id' => 999, 'name' => 'Ghost'],
		], ['dispatchEvents' => false]);

		$this->assertSame(999, (int) ($result['user_id'] ?? 0));
		$user = $db->database()->query('SELECT id, name FROM user WHERE id = 999')->fetch();
		$this->assertNotFalse($user);
		$this->assertSame('Ghost', $user['name']);
	}

	public function testMissingExplicitUpdateItemFails(): void
	{
		[$service] = $this->attachmentOps();

		try {
			$service->update('post', '1', [
				'attachments' => [
					'update' => [['id' => 999, 'title' => 'Ghost']],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
			$this->assertSame('attachments.update.0', $error->getField());
		}
	}

	public function testMissingExplicitDeleteItemFails(): void
	{
		[$service] = $this->attachmentOps();

		try {
			$service->update('post', '1', [
				'attachments' => [
					'delete' => [999],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
			$this->assertSame('attachments.delete.0', $error->getField());
		}
	}

	public function testExplicitO2MUpdateOutOfScopeFails(): void
	{
		[$service, $db] = $this->twoParentAttachmentOps();

		try {
			$service->update('post', '1', [
				'attachments' => [
					'update' => [['id' => 20, 'title' => 'Hijack']],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected INVALID_RELATION_TARGET');
		} catch (RestApiError $error) {
			$this->assertSame('INVALID_RELATION_TARGET', $error->getErrorCode());
			$this->assertSame('attachments.update.0', $error->getField());
		}

		$stmt = $db->database()->query('SELECT title FROM post_attachment WHERE id = 20');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertSame('Other', $row['title']);
	}

	public function testExplicitO2MDeleteOutOfScopeFails(): void
	{
		[$service, $db] = $this->twoParentAttachmentOps();

		try {
			$service->update('post', '1', [
				'attachments' => [
					'delete' => [20],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected INVALID_RELATION_TARGET');
		} catch (RestApiError $error) {
			$this->assertSame('INVALID_RELATION_TARGET', $error->getErrorCode());
			$this->assertSame('attachments.delete.0', $error->getField());
		}

		$this->assertSame(1, $this->countRows($db, 'post_attachment', 'id = 20'));
	}

	public function testExplicitO2MInScopeUpdateAndDeleteSucceed(): void
	{
		[$service, $db] = $this->twoParentAttachmentOps();

		$service->update('post', '1', [
			'attachments' => [
				'update' => [['id' => 10, 'title' => 'Updated']],
				'delete' => [11],
			],
		], ['dispatchEvents' => false]);

		$stmt = $db->database()->query('SELECT id, title FROM post_attachment WHERE post_id = 1 ORDER BY id');
		$rows = $stmt->fetchAll();
		$stmt->close();
		$this->assertCount(1, $rows);
		$this->assertSame(10, (int) $rows[0]['id']);
		$this->assertSame('Updated', $rows[0]['title']);
		$this->assertSame(1, $this->countRows($db, 'post_attachment', 'id = 20'));
	}

	public function testExplicitM2MUpdateOutOfScopeFails(): void
	{
		[$service] = $this->ops();

		try {
			// Tag 3 exists but is not linked to post 1 (post 1 has tags 1,2).
			$service->update('post', '1', [
				'tags' => [
					'update' => [['id' => 3, 'name' => 'Hijack']],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected INVALID_RELATION_TARGET');
		} catch (RestApiError $error) {
			$this->assertSame('INVALID_RELATION_TARGET', $error->getErrorCode());
			$this->assertSame('tags.update.0', $error->getField());
		}
	}

	public function testExplicitM2MDeleteOutOfScopeFails(): void
	{
		[$service, $db] = $this->ops();

		try {
			$service->update('post', '1', [
				'tags' => [
					'delete' => [3],
				],
			], ['dispatchEvents' => false]);
			$this->fail('Expected INVALID_RELATION_TARGET');
		} catch (RestApiError $error) {
			$this->assertSame('INVALID_RELATION_TARGET', $error->getErrorCode());
			$this->assertSame('tags.delete.0', $error->getField());
		}

		$this->assertSame(1, $this->countRows($db, 'tag', 'id = 3'));
	}

	public function testImplicitAssignsUnrelatedExistingItem(): void
	{
		[$service, $db] = $this->ops();

		$service->update('post', '1', [
			'tags' => [1, 2, 3],
		], ['dispatchEvents' => false]);

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$ids = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();
		$this->assertSame([1, 2, 3], $ids);
	}

	public function testImplicitOmitsUnlinkWithoutDelete(): void
	{
		[$service, $db] = $this->ops();

		$service->update('post', '1', [
			'tags' => [2],
		], ['dispatchEvents' => false]);

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$ids = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();
		$this->assertSame([2], $ids);
		$this->assertSame(1, $this->countRows($db, 'tag', 'id = 1'));
	}

	public function testImplicitExistingItemWithUpdatesAssignsAndUpdates(): void
	{
		[$service, $db] = $this->ops();

		$service->update('post', '1', [
			'tags' => [
				['id' => 3, 'name' => 'REST Updated'],
			],
		], ['dispatchEvents' => false]);

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$ids = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();
		$this->assertSame([3], $ids);

		$stmt = $db->database()->query('SELECT name FROM tag WHERE id = 3');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertSame('REST Updated', $row['name']);
	}

	public function testDuplicateImplicitScalarIdentitiesRejected(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$parser = new DirectusPayloadParser();

		try {
			$parser->parse($registry->getCollection('post'), [
				'title' => 'X',
				'tags' => [1, 1],
			]);
			$this->fail('Expected DUPLICATE_RELATED_IDENTITY');
		} catch (RestApiError $error) {
			$this->assertSame('DUPLICATE_RELATED_IDENTITY', $error->getErrorCode());
			$this->assertSame('tags.1', $error->getField());
		}
	}

	public function testDuplicateImplicitScalarAndObjectRejected(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$parser = new DirectusPayloadParser();

		try {
			$parser->parse($registry->getCollection('post'), [
				'tags' => [3, ['id' => 3, 'name' => 'Updated']],
			]);
			$this->fail('Expected DUPLICATE_RELATED_IDENTITY');
		} catch (RestApiError $error) {
			$this->assertSame('DUPLICATE_RELATED_IDENTITY', $error->getErrorCode());
			$this->assertSame('tags.1', $error->getField());
		}
	}

	public function testDuplicateExplicitUpdateAndDeleteRejected(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$registry->collection('post_attachment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->end();
		$registry->getCollection('post')->hasMany('attachments', 'post_attachment')
			->innerKey('id')->outerKey('post_id')->end();

		$parser = new DirectusPayloadParser();

		try {
			$parser->parse($registry->getCollection('post'), [
				'attachments' => [
					'update' => [
						['id' => 3, 'title' => 'A'],
						['id' => 3, 'title' => 'B'],
					],
				],
			]);
			$this->fail('Expected duplicate update rejection');
		} catch (RestApiError $error) {
			$this->assertSame('DUPLICATE_RELATED_IDENTITY', $error->getErrorCode());
			$this->assertSame('attachments.update.1', $error->getField());
		}

		try {
			$parser->parse($registry->getCollection('post'), [
				'attachments' => [
					'delete' => [4, 4],
				],
			]);
			$this->fail('Expected duplicate delete rejection');
		} catch (RestApiError $error) {
			$this->assertSame('DUPLICATE_RELATED_IDENTITY', $error->getErrorCode());
			$this->assertSame('attachments.delete.1', $error->getField());
		}

		try {
			$parser->parse($registry->getCollection('post'), [
				'attachments' => [
					'update' => [['id' => 5, 'title' => 'A']],
					'delete' => [5],
				],
			]);
			$this->fail('Expected update/delete overlap rejection');
		} catch (RestApiError $error) {
			$this->assertSame('DUPLICATE_RELATED_IDENTITY', $error->getErrorCode());
			$this->assertSame('attachments.delete.0', $error->getField());
		}
	}

	public function testCompositeKeyFieldOrderNormalizesForDuplicateDetection(): void
	{
		$registry = new Registry();
		$registry->collection('junction')
			->primaryKey('left_id', 'right_id')
			->field('left_id', 'int')->type('int')->nullable(false)->end()
			->field('right_id', 'int')->type('int')->nullable(false)->end()
			->end();

		$collection = $registry->getCollection('junction');
		$first = new Key($collection, ['left_id' => 1, 'right_id' => 2]);
		$second = new Key($collection, ['right_id' => 2, 'left_id' => 1]);

		$this->assertTrue($first->equals($second));
		$this->assertSame($first->getHash(), $second->getHash());
	}

	public function testBeforeHookScalarChangePersists(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		RestHooks::for($registry->getCollection('user'))
			->on('create.before', static function (ItemCreating $event): void {
				$event->getState()->setValue('name', 'Hooked');
			});

		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$service = $this->createDirectusOperations($registry, $items);

		$created = $service->create('user', [
			'name' => 'Original',
			'email' => 'hook@test.com',
		]);

		$this->assertSame('Hooked', $created['name']);
	}

	public function testBeforeHookIdentityMutationOnExistingFails(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		RestHooks::for($registry->getCollection('user'))
			->on('update.before', static function (ItemUpdating $event): void {
				$event->getState()->setValue('id', 999);
			});

		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$service = $this->createDirectusOperations($registry, $items);

		try {
			$service->update('user', '1', ['name' => 'Changed']);
			$this->fail('Expected IDENTITY_MUTATION_NOT_ALLOWED');
		} catch (RestApiError $error) {
			$this->assertSame('IDENTITY_MUTATION_NOT_ALLOWED', $error->getErrorCode());
		}
	}

	public function testPreventedBeforeEventSkipsFlushAndAfterEvents(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$afterFired = false;
		RestHooks::for($registry->getCollection('user'))
			->on('create.before', static function (ItemCreating $event): void {
				$event->preventDefault();
			})
			->on('create.after', static function () use (&$afterFired): void {
				$afterFired = true;
			});

		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$service = $this->createDirectusOperations($registry, $items);
		$before = $this->countRows($db, 'user');

		try {
			$service->create('user', ['name' => 'Nope', 'email' => 'nope@test.com']);
			$this->fail('Expected MUTATION_PREVENTED');
		} catch (RestApiError $error) {
			$this->assertSame('MUTATION_PREVENTED', $error->getErrorCode());
		}

		$this->assertFalse($afterFired);
		$this->assertSame($before, $this->countRows($db, 'user'));
	}

	public function testEventOrderParentBeforeChildrenThenChildrenBeforeParentAfter(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$order = [];

		RestHooks::for($registry->getCollection('user'))
			->on('create.before', static function () use (&$order): void {
				$order[] = 'user.before';
			})
			->on('create.after', static function () use (&$order): void {
				$order[] = 'user.after';
			});
		RestHooks::for($registry->getCollection('post'))
			->on('create.before', static function () use (&$order): void {
				$order[] = 'post.before';
			})
			->on('create.after', static function () use (&$order): void {
				$order[] = 'post.after';
			});

		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$service = $this->createDirectusOperations($registry, $items);

		$service->create('user', [
			'name' => 'Nested',
			'email' => 'nested@test.com',
			'posts' => [
				['title' => 'Child', 'content' => 'Body', 'status' => 'draft'],
			],
		]);

		$this->assertSame(
			['user.before', 'post.before', 'post.after', 'user.after'],
			$order,
		);
	}

	public function testBatchRollsBackWhenLaterItemHasMissingRelatedIdentity(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);
		$before = $this->countRows($db, 'post');

		try {
			$coordinator->batchCreate($registry->getCollection('post'), [
				[
					'user_id' => 1,
					'title' => 'Ok',
					'content' => 'Body',
					'status' => 'draft',
				],
				[
					'user_id' => 1,
					'title' => 'Bad',
					'content' => 'Body',
					'status' => 'draft',
					'author' => 999,
				],
			], false);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
		}

		$this->assertSame($before, $this->countRows($db, 'post'));
	}

	public function testBatchNoAfterEventsOnFailure(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$afterCount = 0;
		RestHooks::for($registry->getCollection('post'))
			->on('create.after', static function () use (&$afterCount): void {
				$afterCount++;
			});

		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);

		try {
			$coordinator->batchCreate($registry->getCollection('post'), [
				[
					'user_id' => 1,
					'title' => 'Ok',
					'content' => 'Body',
					'status' => 'draft',
				],
				[
					'user_id' => 1,
					'title' => 'Bad',
					'content' => 'Body',
					'status' => 'draft',
					'tags' => [999],
				],
			], true);
			$this->fail('Expected RELATED_NOT_FOUND');
		} catch (RestApiError) {
			// expected
		}

		$this->assertSame(0, $afterCount);
	}

	public function testBatchItemUpdatesRelatedThenAnotherRootReferencesIt(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);

		$results = $coordinator->batchUpdate($registry->getCollection('post'), [
			[
				'identity' => '1',
				'input' => [
					'tags' => [
						'update' => [['id' => 1, 'name' => 'PHP Batch']],
					],
				],
			],
			[
				'identity' => '2',
				'input' => [
					'tags' => [1],
				],
			],
		], false);

		$this->assertCount(2, $results);

		$stmt = $db->database()->query('SELECT name FROM tag WHERE id = 1');
		$row = $stmt->fetch();
		$stmt->close();
		$this->assertSame('PHP Batch', $row['name']);

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 2 ORDER BY tag_id');
		$ids = array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
		$stmt->close();
		$this->assertSame([1], $ids);
	}

	public function testBatchItemDeletesRelatedThenAnotherRootReferencingItFailsCleanly(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);
		$beforeTags = $this->countRows($db, 'tag');

		try {
			$coordinator->batchUpdate($registry->getCollection('post'), [
				[
					'identity' => '1',
					'input' => [
						'tags' => [
							'delete' => [2],
						],
					],
				],
				[
					'identity' => '2',
					'input' => [
						'tags' => [2],
					],
				],
			], false);
			$this->fail('Expected RELATED_NOT_FOUND after earlier batch root deleted the identity');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
			$this->assertSame('tags.0', $error->getField());
		}

		$this->assertSame($beforeTags, $this->countRows($db, 'tag'));
	}

	public function testBatchTwoRootsAssignSameRelatedItem(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);

		$coordinator->batchUpdate($registry->getCollection('post'), [
			[
				'identity' => '1',
				'input' => ['tags' => [1, 3]],
			],
			[
				'identity' => '2',
				'input' => ['tags' => [3]],
			],
		], false);

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 1 ORDER BY tag_id');
		$this->assertSame([1, 3], array_map('intval', array_column($stmt->fetchAll(), 'tag_id')));
		$stmt->close();

		$stmt = $db->database()->query('SELECT tag_id FROM post_tag WHERE post_id = 2 ORDER BY tag_id');
		$this->assertSame([3], array_map('intval', array_column($stmt->fetchAll(), 'tag_id')));
		$stmt->close();
	}

	public function testBatchCannotReferenceIdentityCreatedByEarlierRootInSameBatch(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);
		$coordinator = $this->coordinator($registry, $items);

		try {
			$coordinator->batchCreate($registry->getCollection('user'), [
				[
					'id' => 50,
					'name' => 'Manual',
					'email' => 'manual@test.com',
				],
				[
					'name' => 'ChildOwner',
					'email' => 'child@test.com',
					'posts' => [
						[
							'title' => 'Needs author 50',
							'content' => 'Body',
							'status' => 'draft',
							'author' => 50,
						],
					],
				],
			], false);
			$this->fail('Expected RELATED_NOT_FOUND for cross-root create-then-reference');
		} catch (RestApiError $error) {
			$this->assertSame('RELATED_NOT_FOUND', $error->getErrorCode());
		}

		$this->assertSame(0, $this->countRows($db, 'user', 'id = 50'));
	}

	/**
	 * @return array{0: object, 1: CycleSqliteTestDatabase}
	 */
	private function ops(): array
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$db = $this->createFullDatabase();
		$items = $this->createItems($registry, $db);

		return [$this->createDirectusOperations($registry, $items), $db];
	}

	/**
	 * @return array{0: object, 1: CycleSqliteTestDatabase}
	 */
	private function attachmentOps(): array
	{
		return $this->twoParentAttachmentOps(singleParent: true);
	}

	/**
	 * @return array{0: object, 1: CycleSqliteTestDatabase}
	 */
	private function twoParentAttachmentOps(bool $singleParent = false): array
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$registry->collection('post_attachment')
			->primaryKey('id')
			->field('id', 'int')->type('int')->nullable(false)->autoIncrement(true)->end()
			->field('post_id', 'int')->type('int')->nullable(false)->end()
			->field('title', 'string')->type('string')->nullable(true)->end()
			->end();
		$registry->getCollection('post')->hasMany('attachments', 'post_attachment')
			->innerKey('id')->outerKey('post_id')->end();

		$rows = [
			['id' => 10, 'post_id' => 1, 'title' => 'Keep'],
			['id' => 11, 'post_id' => 1, 'title' => 'Remove'],
		];
		if (! $singleParent) {
			$rows[] = ['id' => 20, 'post_id' => 2, 'title' => 'Other'];
		}

		$db = new CycleSqliteTestDatabase([
			'user' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT', 'email' => 'TEXT', 'password' => 'TEXT'],
				'rows' => [['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'password' => 'secret1']],
			],
			'post' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'user_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
					'content' => 'TEXT',
					'status' => 'TEXT',
					'created_at' => 'TEXT',
				],
				'rows' => [
					['id' => 1, 'user_id' => 1, 'title' => 'Post', 'content' => 'Body', 'status' => 'published', 'created_at' => null],
					['id' => 2, 'user_id' => 1, 'title' => 'Other', 'content' => 'Body', 'status' => 'published', 'created_at' => null],
				],
			],
			'post_attachment' => [
				'columns' => [
					'id' => 'INTEGER PRIMARY KEY',
					'post_id' => 'INTEGER NOT NULL',
					'title' => 'TEXT',
				],
				'rows' => $rows,
			],
			'comment' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'post_id' => 'INTEGER NOT NULL', 'body' => 'TEXT', 'author' => 'TEXT'],
				'rows' => [],
			],
			'tag' => [
				'columns' => ['id' => 'INTEGER PRIMARY KEY', 'name' => 'TEXT'],
				'rows' => [],
			],
			'post_tag' => [
				'columns' => ['post_id' => 'INTEGER NOT NULL', 'tag_id' => 'INTEGER NOT NULL'],
				'rows' => [],
			],
		]);

		$items = $this->createItems($registry, $db);

		return [$this->createDirectusOperations($registry, $items), $db];
	}

	private function coordinator(Registry $registry, ItemRepositoryInterface $items): MutationCoordinator
	{
		$runtime = $this->runtimeForItems($items);
		$sessions = new SessionFactory($runtime);

		return new MutationCoordinator(
			$sessions,
			new DirectusMutationBinder($items, $sessions),
			new DirectusPayloadParser(),
			$items,
			$this->noopHookDispatcher($registry),
			$runtime,
		);
	}

	private function countRows(CycleSqliteTestDatabase $db, string $table, ?string $where = null): int
	{
		$sql = 'SELECT COUNT(*) AS c FROM ' . $table;
		if ($where !== null) {
			$sql .= ' WHERE ' . $where;
		}
		$stmt = $db->database()->query($sql);
		$row = $stmt->fetch();
		$stmt->close();

		return (int) ($row['c'] ?? 0);
	}
}
