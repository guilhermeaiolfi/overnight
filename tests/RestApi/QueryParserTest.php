<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\RawSqlExpression;
use ON\Data\Query\Selection\SelectionTag;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\RelationLoadHint;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Query\Parser\CmsQueryParser;
use ON\RestApi\Query\QueryContext;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class QueryParserTest extends TestCase
{
	use RestApiTestFixtures;

	public function testDirectusRelationAliasesConfigureIndependentBranches(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');
		$parser = $this->createQueryParser();

		$context = new QueryContext();
		$query = $parser->parse($collection, [
			'fields' => 'id,name,published_posts.title',
			'alias' => [
				'published_posts' => 'posts',
			],
			'deep' => [
				'published_posts' => [
					'_filter' => ['status' => ['_eq' => 'published']],
				],
			],
		], $context);

		$this->assertSame('published_posts', $context->getRelationResponseNames()['posts'] ?? null);
		$posts = $query->relation('posts');
		$this->assertTrue($posts->isSelected());
		$this->assertNotEmpty($posts->getConditions());
		$condition = $posts->getConditions()[0];
		$this->assertInstanceOf(ComparisonCondition::class, $condition);
		$this->assertSame(ComparisonOperator::EQ, $condition->getOperator());
	}

	public function testDirectusFunctionsAggregatesAndSearchBecomeSelectQuery(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('post');
		$parser = $this->createQueryParser();

		$context = new QueryContext(databaseType: 'sqlite');
		$query = $parser->parse($collection, [
			'filter' => ['year(created_at)' => ['_eq' => 2026]],
			'search' => 'GraphQL',
			'sort' => '-month(created_at)',
			'aggregate' => ['countDistinct' => 'user_id'],
			'groupBy' => ['year(created_at)'],
		], $context);

		$this->assertTrue($context->isAggregate());
		$this->assertSame('countDistinct', $context->getAggregates()[0]['function']);
		$this->assertSame('user_id', $context->getAggregates()[0]['field']);
		$this->assertSame('year(created_at)', $context->getGroupBy()[0]['responseName']);

		$selections = $query->getSelections()->getAll();
		$this->assertNotEmpty($selections);
		$hasCountDistinct = false;
		foreach ($selections as $selection) {
			$expr = $selection->getExpression();
			if ($expr instanceof \ON\Data\Query\Expression\AliasedExpression) {
				$expr = $expr->getExpression();
			}
			if ($expr instanceof AggregateExpression && $expr->getFunction() === AggregateFunction::COUNT_DISTINCT) {
				$hasCountDistinct = true;
			}
		}
		$this->assertTrue($hasCountDistinct);

		$filterContext = new QueryContext(databaseType: 'sqlite');
		$filterQuery = $parser->parse($collection, [
			'filter' => ['year(created_at)' => ['_eq' => 2026]],
			'search' => 'GraphQL',
			'sort' => '-month(created_at)',
		], $filterContext);

		$conditions = $filterQuery->getConditions();
		$this->assertGreaterThanOrEqual(2, count($conditions));
		$sorts = $filterQuery->getSorts();
		$this->assertNotEmpty($sorts);
		$this->assertInstanceOf(RawSqlExpression::class, $sorts[0]->getExpression());
	}

	public function testDirectusNestedAliasesAreScopedByRelationPath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');
		$parser = $this->createQueryParser();

		$context = new QueryContext();
		$query = $parser->parse($collection, [
			'fields' => 'posts.published_comments.body',
			'alias' => [
				'posts.published_comments' => 'comments',
			],
			'deep' => [
				'posts' => [
					'published_comments' => [
						'_filter' => ['author' => ['_eq' => 'Alice']],
					],
				],
			],
		], $context);

		$posts = $query->relation('posts');
		$comments = $posts->relation('comments');
		$this->assertTrue($comments->isSelected());
		$this->assertSame('published_comments', $context->getRelationResponseNames()['posts.comments'] ?? null);
		$this->assertNotEmpty($comments->getConditions());
	}

	public function testCmsQueryLanguageMapsToSameSelectionAst(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$query = (new CmsQueryParser($registry))->parseQuery('post{id,!comments{body},~author{name},*}');
		$nodes = $query->selection->nodes;
		$relations = $this->relationsByResponseName($nodes);

		$this->assertSame('post', $query->collection);
		$this->assertInstanceOf(FieldSelection::class, $nodes[0]);
		$this->assertSame('id', $nodes[0]->responseName);
		$this->assertInstanceOf(WildcardSelection::class, $nodes[3]);

		$this->assertSame(RelationLoadHint::Join, $relations['comments']->loadHint);
		$this->assertSame(RelationLoadHint::LeftJoin, $relations['author']->loadHint);
		$this->assertSame('body', $relations['comments']->query->selection->nodes[0]->responseName);
		$this->assertSame('name', $relations['author']->query->selection->nodes[0]->responseName);
	}

	public function testCmsDottedRelationAndDirectusDottedRelationShareShape(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);

		$cms = (new CmsQueryParser($registry))->parseQuery('post{id,comments{body}}');
		$directus = $this->createQueryParser()->parse(
			$registry->getCollection('post'),
			['fields' => 'id,comments.body'],
			new QueryContext(),
		);

		$this->assertSame('id', $cms->selection->nodes[0]->responseName);
		$this->assertTrue($directus->relation('comments')->isSelected());
		$this->assertContains('id', array_map(
			static fn ($s) => $s->getExpression() instanceof FieldRef
				? $s->getExpression()->getName()
				: null,
			$directus->getSelections()->getByTag(SelectionTag::PUBLIC),
		));
	}

	/**
	 * @param list<object> $nodes
	 * @return array<string, object>
	 */
	private function relationsByResponseName(array $nodes): array
	{
		$result = [];
		foreach ($nodes as $node) {
			if (isset($node->responseName) && isset($node->loadHint)) {
				$result[$node->responseName] = $node;
			}
		}

		return $result;
	}
}
