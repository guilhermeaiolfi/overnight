<?php

declare(strict_types=1);

namespace Tests\ON\RestApi;

use ON\ORM\Definition\Registry;
use ON\RestApi\Query\Node\AggregateFunction;
use ON\RestApi\Query\Node\ComparisonFilter;
use ON\RestApi\Query\Node\ComparisonOperator;
use ON\RestApi\Query\Node\FieldExpression;
use ON\RestApi\Query\Node\FieldSelection;
use ON\RestApi\Query\Node\FunctionExpression;
use ON\RestApi\Query\Node\RelationLoadHint;
use ON\RestApi\Query\Node\RelationSelection;
use ON\RestApi\Query\Node\SortDirection;
use ON\RestApi\Query\Node\WildcardSelection;
use ON\RestApi\Query\Parser\CmsQueryParser;
use ON\RestApi\Query\Parser\DirectusQueryParser;
use PHPUnit\Framework\TestCase;
use Tests\ON\RestApi\Support\RestApiTestFixtures;

final class QueryParserTest extends TestCase
{
	use RestApiTestFixtures;

	public function testDirectusRelationAliasesHaveIndependentQuerySpecs(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');

		$query = (new DirectusQueryParser())->parse($collection, [
			'fields' => 'id,name,published_posts.title,recent_posts.title',
			'alias' => [
				'published_posts' => 'posts',
				'recent_posts' => 'posts',
			],
			'deep' => [
				'published_posts' => [
					'_filter' => ['status' => ['_eq' => 'published']],
				],
				'recent_posts' => [
					'_sort' => '-created_at',
					'_limit' => 3,
				],
			],
		]);

		$relations = $this->relationsByResponseName($query->selection->nodes);

		$this->assertArrayHasKey('published_posts', $relations);
		$this->assertArrayHasKey('recent_posts', $relations);
		$this->assertSame('posts', $relations['published_posts']->relationName);
		$this->assertSame('posts', $relations['recent_posts']->relationName);

		$publishedFilter = $relations['published_posts']->query->filter;
		$this->assertInstanceOf(ComparisonFilter::class, $publishedFilter);
		$this->assertSame(ComparisonOperator::Eq, $publishedFilter->operator);
		$this->assertSame('published', $publishedFilter->right->value());

		$this->assertSame(SortDirection::Desc, $relations['recent_posts']->query->sort[0]->direction);
		$this->assertSame(3, $relations['recent_posts']->query->pagination->limit);
	}

	public function testDirectusFunctionsAggregatesAndSearchBecomeAstNodes(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('post');

		$query = (new DirectusQueryParser())->parse($collection, [
			'filter' => ['year(created_at)' => ['_eq' => 2026]],
			'search' => 'GraphQL',
			'sort' => '-month(created_at)',
			'aggregate' => ['countDistinct' => 'user_id'],
			'groupBy' => ['year(created_at)'],
		]);

		$this->assertInstanceOf(WildcardSelection::class, $query->selection->nodes[0]);
		$this->assertSame('GraphQL', $query->search->term);

		$this->assertInstanceOf(ComparisonFilter::class, $query->filter);
		$this->assertInstanceOf(FunctionExpression::class, $query->filter->left);
		$this->assertSame('year', $query->filter->left->name);

		$this->assertInstanceOf(FunctionExpression::class, $query->sort[0]->expression);
		$this->assertSame('month', $query->sort[0]->expression->name);

		$this->assertSame(AggregateFunction::Count, $query->aggregate[0]->expression->function);
		$this->assertTrue($query->aggregate[0]->expression->distinct);
		$this->assertSame('countDistinct', $query->aggregate[0]->responseFunction);

		$this->assertInstanceOf(FunctionExpression::class, $query->groupBy[0]->expression);
		$this->assertSame('year_created_at', $query->groupBy[0]->alias);
	}

	public function testDirectusNestedAliasesAreScopedByRelationPath(): void
	{
		$registry = new Registry();
		$this->createFullSchema($registry);
		$collection = $registry->getCollection('user');

		$query = (new DirectusQueryParser())->parse($collection, [
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
		]);

		$posts = $this->relationsByResponseName($query->selection->nodes)['posts'];
		$comments = $this->relationsByResponseName($posts->query->selection->nodes)['published_comments'];

		$this->assertSame('comments', $comments->relationName);
		$this->assertSame('comment', $comments->targetCollection);
		$this->assertInstanceOf(ComparisonFilter::class, $comments->query->filter);
		$this->assertSame('Alice', $comments->query->filter->right->value());
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

		$cms = (new CmsQueryParser($registry))->parseQuery('post{author.name}');
		$directus = (new DirectusQueryParser())->parse($registry->getCollection('post'), [
			'fields' => 'author.name',
		]);

		$cmsAuthor = $this->relationsByResponseName($cms->selection->nodes)['author'];
		$directusAuthor = $this->relationsByResponseName($directus->selection->nodes)['author'];

		$this->assertSame($cmsAuthor->relationName, $directusAuthor->relationName);
		$this->assertSame(
			$cmsAuthor->query->selection->nodes[0]->responseName,
			$directusAuthor->query->selection->nodes[0]->responseName
		);
		$this->assertInstanceOf(FieldExpression::class, $directusAuthor->query->selection->nodes[0]->field);
	}

	/**
	 * @param list<object> $nodes
	 * @return array<string, RelationSelection>
	 */
	private function relationsByResponseName(array $nodes): array
	{
		$relations = [];
		foreach ($nodes as $node) {
			if ($node instanceof RelationSelection) {
				$relations[$node->responseName] = $node;
			}
		}

		return $relations;
	}
}
