<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\RestApi\Error\RestApiError;
use ON\RestApi\Mutation\Payload\DirectusPayloadParser;
use ON\RestApi\Query\QueryContext;
use ON\RestApi\RestApiConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class RestApiQuerySecurityTest extends TestCase
{
	use RestApiTestFixtures;

	public static function invalidQueries(): iterable
	{
		yield 'hidden scalar selection' => [['fields' => 'password'], 'INVALID_FIELD'];
		yield 'hidden grouping field' => [['groupBy' => 'password'], 'INVALID_FIELD'];
		yield 'hidden aggregate field' => [['aggregate' => ['min' => 'password']], 'INVALID_FIELD'];
		yield 'hidden sort field' => [['sort' => 'password'], 'INVALID_FIELD'];
		yield 'non-filterable field' => [['filter' => ['email' => ['_eq' => 'a@example.com']]], 'INVALID_FIELD'];
		yield 'unknown field' => [['filter' => ['unknown' => ['_eq' => 1]]], 'INVALID_FIELD'];
		yield 'unknown operator' => [['filter' => ['name' => ['_unknown' => 'Ana']]], 'INVALID_QUERY'];
		yield 'short range' => [['filter' => ['id' => ['_between' => [1]]]], 'INVALID_QUERY'];
		yield 'nested field name' => [['fields' => [['name']]], 'INVALID_QUERY'];
		yield 'nested sort name' => [['sort' => [['name']]], 'INVALID_QUERY'];
		yield 'array search' => [['search' => ['Ana']], 'INVALID_QUERY'];
		yield 'array scalar filter operand' => [['filter' => ['name' => ['_contains' => ['Ana']]]], 'INVALID_QUERY'];
		yield 'array page' => [['page' => [1]], 'INVALID_QUERY'];
		yield 'oversized page' => [['page' => (string) PHP_INT_MAX], 'INVALID_QUERY'];
	}

	#[DataProvider('invalidQueries')]
	public function testInvalidQueryParametersReturnBadRequest(array $query, string $code): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$user = $registry->getCollection('user');
		$user->getField('email')->filterable(false);

		$this->assertBadRequest($code, fn () => $this->createQueryParser()->parse($user, $query, new QueryContext()));
	}

	public function testHiddenRelationTargetsAreUnavailableEvenThroughAliasesAndNestedPayloads(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$registry->getCollection('post')->hidden(true);
		$user = $registry->getCollection('user');
		$parser = $this->createQueryParser();

		$queries = [
			['fields' => 'articles.title', 'alias' => ['articles' => 'posts']],
			['filter' => ['posts.title' => ['_eq' => 'Article']]],
			['deep' => ['posts' => ['_sort' => 'title']]],
		];
		foreach ($queries as $query) {
			$this->assertBadRequest('INVALID_FIELD', fn () => $parser->parse($user, $query, new QueryContext()));
		}

		$this->assertBadRequest(
			'INVALID_FIELD',
			fn () => (new DirectusPayloadParser())->parse($user, ['posts' => [['title' => 'Article']]]),
		);
	}

	public function testFiltersAndSortingOnVisibleRelationsExcludeHiddenFields(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$registry->getCollection('post')->getField('content')->hidden(true);
		$user = $registry->getCollection('user');
		$parser = $this->createQueryParser();

		foreach ([
			['filter' => ['posts' => ['content' => ['_eq' => 'Draft']]]],
			['fields' => 'posts.title', 'deep' => ['posts' => ['_filter' => ['content' => ['_eq' => 'Draft']]]]],
			['fields' => 'posts.title', 'deep' => ['posts' => ['_sort' => 'content']]],
		] as $query) {
			$this->assertBadRequest('INVALID_FIELD', fn () => $parser->parse($user, $query, new QueryContext()));
		}
	}

	public function testGetAndListEnforceConfiguredRelationAndFilterDepth(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$config = new RestApiConfig(['maxRelationDepth' => 1, 'maxFilterDepth' => 2]);
		$actions = $this->createDirectusReadActions($registry, $this->createFullDatabase(), $config);
		$user = $registry->getCollection('user');

		$allowed = $actions->get($user, '1', ['fields' => 'name,posts.title']);
		$this->assertSame('John', $allowed['name']);
		$this->assertCount(2, $allowed['posts']);

		$this->assertBadRequest('INVALID_QUERY', fn () => $actions->get($user, '1', ['fields' => 'posts.author.name']));
		$this->assertBadRequest('INVALID_QUERY', fn () => $actions->list($user, [
			'filter' => ['_and' => [['_or' => [['name' => ['_eq' => 'John']]]]]],
		]));
	}

	public function testNestedWildcardsDoNotDiscardFieldsAndHasManyUsesDefaultLimit(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$actions = $this->createDirectusReadActions(
			$registry,
			$this->createFullDatabase(),
			new RestApiConfig(['defaultLimit' => 1]),
		);
		$user = $registry->getCollection('user');

		$item = $actions->get($user, '1', ['fields' => '*,posts.*,posts.author.name']);
		$this->assertSame('John', $item['name']);
		$this->assertArrayNotHasKey('password', $item);
		$this->assertCount(1, $item['posts']);
		$this->assertSame('PHP Tips', $item['posts'][0]['title']);
		$this->assertSame('Learn PHP', $item['posts'][0]['content']);
		$this->assertSame(['name' => 'John'], $item['posts'][0]['author']);
	}

	public function testGroupByAndAggregateResultsRespectMaxLimit(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$actions = $this->createDirectusReadActions(
			$registry,
			$this->createFullDatabase(),
			new RestApiConfig(['maxLimit' => 1]),
		);
		$post = $registry->getCollection('post');

		$first = $actions->aggregate($post, [
			'groupBy' => 'status',
			'aggregate' => ['count' => 'id'],
			'limit' => 50,
		]);
		$second = $actions->aggregate($post, [
			'groupBy' => 'status',
			'aggregate' => ['count' => 'id'],
			'limit' => 50,
			'page' => 2,
		]);

		$this->assertCount(1, $first);
		$this->assertCount(1, $second);
		$this->assertNotSame($first[0]['status'], $second[0]['status']);
		$this->assertCount(1, $actions->aggregate($post, ['groupBy' => 'status']));
	}

	public function testEmptyMembershipListsRetainTheirMeaningInsideLogicalGroups(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$actions = $this->createDirectusReadActions($registry, $this->createFullDatabase());
		$user = $registry->getCollection('user');

		$positive = $actions->list($user, [
			'filter' => ['_or' => [['id' => ['_in' => []]], ['id' => ['_eq' => 2]]]],
		]);
		$negative = $actions->list($user, [
			'filter' => ['_or' => [['id' => ['_nin' => []]], ['id' => ['_eq' => 2]]]],
			'sort' => 'id',
		]);

		$this->assertSame([2], array_column($positive['data'], 'id'));
		$this->assertSame([1, 2, 3], array_column($negative['data'], 'id'));
	}

	public function testFirstOfManyKeepsDefinitionOrderWithDeepPagination(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$user = $registry->getCollection('user');
		$user->relation('latest_post', FirstOfManyRelation::class)
			->collection('post')->innerKey('id')->outerKey('user_id')
			->orderBy(['created_at' => 'desc'])->end();
		$actions = $this->createDirectusReadActions($registry, $this->createFullDatabase());

		$item = $actions->get($user, '1', [
			'fields' => 'latest_post.title',
			'deep' => ['latest_post' => ['_limit' => 1]],
		]);

		$this->assertSame('Draft Post', $item['latest_post']['title']);
	}

	public function testSearchAndTextFiltersTreatWildcardCharactersAsLiteralText(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$database = $this->createFullDatabase();

		$literal = 'Guide 50%_\\!';
		$database->database()->execute('INSERT INTO post (id, user_id, title) VALUES (?, ?, ?)', [4, 1, $literal]);
		$database->database()->execute('INSERT INTO post (id, user_id, title) VALUES (?, ?, ?)', [5, 1, 'Guide 50xx!']);
		$actions = $this->createDirectusReadActions($registry, $database);
		$post = $registry->getCollection('post');

		foreach ([
			['search' => '%_\\!'],
			['filter' => ['title' => ['_contains' => '%_\\!']]],
			['filter' => ['title' => ['_starts_with' => 'Guide 50%_']]],
			['filter' => ['title' => ['_ends_with' => '%_\\!']]],
		] as $query) {
			$matches = $actions->list($post, $query);
			$this->assertSame([$literal], array_column($matches['data'], 'title'));
		}

		$matches = $actions->list($post, [
			'filter' => ['title' => ['_ncontains' => '%_\\!']],
			'sort' => 'id',
		]);
		$this->assertSame([1, 2, 3, 5], array_column($matches['data'], 'id'));
	}

	private function assertBadRequest(string $code, callable $action): void
	{
		try {
			$action();
			$this->fail('The query should have been rejected.');
		} catch (RestApiError $error) {
			$this->assertSame(400, $error->getHttpStatus());
			$this->assertSame($code, $error->getErrorCode());
		}
	}
}
