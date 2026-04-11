# Testing

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/Router/ActionMiddlewareDecoratorTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

## Testing Controllers/Pages

When testing controllers that use route parameter injection, follow this pattern:

### Test Setup

```php
use ON\Container\Executor\Executor;
use ON\Container\Executor\TypeHintContainerResolver;
use ON\Router\ActionMiddlewareDecorator;
use ON\Router\RouteResult;
use PHPUnit\Framework\TestCase;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\DefaultValueResolver;

final class MyControllerTest extends TestCase
{
    private ContainerInterface $container;
    private Executor $executor;
    private MyPage $page;

    protected function setUp(): void
    {
        $this->page = new MyPage();

        // Create container mock
        $this->container = $this->createMock(ContainerInterface::class);

        // Create executor with proper resolver chain
        $parameterResolver = new ResolverChain([
            new TypeHintResolver(),
            new NumericArrayResolver(),
            new AssociativeArrayResolver(),
            new DefaultValueResolver(),
            new TypeHintContainerResolver($this->container),
        ]);
        $this->executor = new Executor($parameterResolver, $this->container);

        // Configure container to return our dependencies
        $this->container->method('get')
            ->willReturnCallback(function (string $class) {
                if ($class === \ON\Application::class) {
                    return $this->createMock(\ON\Application::class);
                }
                if ($class === Executor::class || $class === ExecutorInterface::class) {
                    return $this->executor;
                }
                if ($class === MyPage::class) {
                    return $this->page;
                }
                return null;
            });
    }
}
```

### Testing Route Parameter Injection

```php
public function testRouteParamsAreInjected(): void
{
    // Setup
    $routeResult = RouteResult::fromRoute(
        $this->createMock(Route::class),
        ['id' => '42', 'name' => 'test']
    );

    $request = $this->createMock(ServerRequestInterface::class);
    $request->method('getAttribute')
        ->willReturnCallback(function (string $name) use ($routeResult) {
            if ($name === RouteResult::class) {
                return $routeResult;
            }
            return null;
        });

    $handler = $this->createMock(RequestHandlerInterface::class);

    // Execute
    $decorator = new ActionMiddlewareDecorator(
        $this->container,
        MyPage::class . '::show'
    );
    $decorator->execute($request, $handler);

    // Assert
    $this->assertSame(42, $this->page->receivedId);
    $this->assertSame('test', $this->page->receivedName);
}
```

### Test Fixtures

Use `tests/Fixtures/TestPage.php` as a reference for creating test page classes:

```php
class TestPage
{
    public array $testData = [];

    public function show(int $id, string $name)
    {
        $this->testData['show'] = ['id' => $id, 'name' => $name];
        return new JsonResponse(['success' => true]);
    }

    public function resetTestData(): void
    {
        $this->testData = [];
    }
}
```

## Assertions for Route Parameters

| Test Case | Assertion |
|-----------|-----------|
| Integer parameter | `$this->assertSame(42, $page->testData['id']);` |
| Type is integer | `$this->assertSame('integer', gettype($page->testData['id']));` |
| Float parameter | `$this->assertEqualsWithDelta(19.99, $page->testData['price'], 0.001);` |
| Boolean parameter | `$this->assertTrue($page->testData['active']);` |
| Request injection | `$this->assertInstanceOf(ServerRequestInterface::class, $page->request);` |
| Missing parameter | `$this->assertArrayNotHasKey('method', $page->testData);` |
