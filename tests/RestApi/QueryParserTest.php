<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\QueryFunction\Standard\Temporal\Month;
use ON\Data\Query\QueryFunction\Standard\Temporal\Year;
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

		$context = new QueryContext();
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

		$filterContext = new QueryContext();
		$filterQuery = $parser->parse($collection, [
			'filter' => ['year(created_at)' => ['_eq' => 2026]],
			'search' => 'GraphQL',
			'sort' => '-month(created_at)',
		], $filterContext);

		$conditions = $filterQuery->getConditions();
		$this->assertGreaterThanOrEqual(2, count($conditions));
		$sorts = $filterQuery->getSorts();
		$this->assertNotEmpty($sorts);
		$this->assertInstanceOf(FunctionCallExpression::class, $sorts[0]->getExpression());
		$this->assertSame(Month::class, $sorts[0]->getExpression()->getFunction());

		$filterExpr = null;
		foreach ($conditions as $condition) {
			if (method_exists($condition, 'getLeft')) {
				$left = $condition->getLeft();
				if ($left instanceof FunctionCallExpression) {
					$filterExpr = $left;
					break;
				}
			}
		}
		$this->assertInstanceOf(FunctionCallExpression::class, $filterExpr);
		$this->assertSame(Year::class, $filterExpr->getFunction());
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

	public function testDirectusParserHasNoManualTemporalSqlOrRelationKeyTraversal(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 2) . '/src/RestApi/Query/Parser/DirectusQueryParser.php');

		foreach ([
			'strftime',
			'EXTRACT',
			'YEAR(',
			'MONTH(',
			'DAY(',
			'HOUR(',
			'dateFunctionSql',
			'rawSql(',
			'getInnerKeys(',
			'getOuterKeys(',
			'getThrough(',
			'M2MRelation',
		] as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $contents, $forbidden);
		}

		$this->assertStringContainsString('relatedQuery(', $contents);
		$this->assertStringContainsString('FUNCTION_CLASSES', $contents);
	}
}
