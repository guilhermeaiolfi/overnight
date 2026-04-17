<?php

declare(strict_types=1);

namespace Tests\ON\Router;

use ON\Router\ActionMiddlewareDecorator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tests\ON\Fixtures\TestPage;

final class ActionMiddlewareDecoratorTest extends TestCase
{
	private ContainerInterface $container;
	private TestPage $page;

	protected function setUp(): void
	{
		$this->page = new TestPage();
		$this->container = $this->createMock(ContainerInterface::class);

		$this->container->method('get')
			->willReturnCallback(function (string $class) {
				if ($class === TestPage::class) {
					return $this->page;
				}
				return null;
			});
	}

	public function testParsesClassAndMethod(): void
	{
		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testIt'
		);

		$this->assertSame(TestPage::class, $decorator->getClassName());
		$this->assertSame('testIt', $decorator->getMethod());
	}

	public function testResolvesPageInstanceFromContainer(): void
	{
		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			TestPage::class . '::testIt'
		);

		$this->assertSame($this->page, $decorator->getPageInstance());
	}

	public function testDefaultMethodIsIndex(): void
	{
		$decorator = new ActionMiddlewareDecorator(
			$this->container,
			'not-a-class-method-pair'
		);

		$this->assertSame('index', $decorator->getMethod());
		$this->assertNull($decorator->getPageInstance());
	}
}
