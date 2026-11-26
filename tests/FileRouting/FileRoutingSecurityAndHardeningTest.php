<?php

declare(strict_types=1);

namespace Tests\ON\FileRouting;

use FilesystemIterator;
use Laminas\Diactoros\ServerRequest;
use League\Plates\Engine;
use ON\Application;
use ON\Config\ConfigExtension;
use ON\Container\ContainerExtension;
use ON\FileRouting\FileRouter;
use ON\FileRouting\FileRoutingCache;
use ON\FileRouting\FileRoutingConfig;
use ON\FileRouting\FileRoutingExtension;
use ON\FileRouting\Page\ApiPage;
use ON\FileRouting\Page\MainPage;
use ON\RequestStack;
use ON\Router\RouterConfig;
use ON\Router\RouteResult;
use ON\Router\RouterExtension;
use ON\Router\RouterInterface;
use ON\View\Plates\PlatesRenderer;
use ON\View\ViewConfig;
use ON\View\ViewManager;
use ON\View\ViewResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileRoutingSecurityAndHardeningTest extends TestCase
{
	private string $previousCwd;
	private string $projectDir;

	protected function setUp(): void
	{
		$this->previousCwd = getcwd();
		$this->projectDir = $this->createProjectDir();
	}

	protected function tearDown(): void
	{
		chdir($this->previousCwd);
		Application::$instance = null;
		$this->removeDirectory($this->projectDir);
	}

	public function testExtensionIdIsFilerouting(): void
	{
		$this->assertSame('filerouting', FileRoutingExtension::ID);
	}

	public function testApiRouteIsDisabledByDefault(): void
	{
		$app = $this->createApplication(exposeApi: null);
		$routerConfig = $app->config->get(RouterConfig::class);
		$routesByName = $this->indexRoutesByName($routerConfig);

		$this->assertArrayNotHasKey('filerouting.api', $routesByName);
		$this->assertArrayHasKey('filerouting.page', $routesByName);
		$this->assertTrue($app->hasExtension('filerouting'));
	}

	public function testApiRouteIsMountedOnlyWhenExplicitlyEnabled(): void
	{
		$app = $this->createApplication(exposeApi: true);
		$routerConfig = $app->config->get(RouterConfig::class);
		$routesByName = $this->indexRoutesByName($routerConfig);

		$this->assertArrayHasKey('filerouting.api', $routesByName);
		$this->assertSame('/__fileRouting', $routesByName['filerouting.api']->getPath());
	}

	public function testApiPageReturnsRelativePathsAndBlocksTraversal(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'home.php', '<div>home</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'blog' . DIRECTORY_SEPARATOR . 'post.php', '<div>post</div>');
		$this->writeFile($this->projectDir . DIRECTORY_SEPARATOR . 'secret.txt', 'top-secret');

		$config = new FileRoutingConfig(['pagesPath' => $pagesDir]);
		$apiPage = new ApiPage(
			$this->createMock(RouterInterface::class),
			new ViewConfig(),
			$config
		);

		// Default (no location param) lists files under pagesPath with relative paths only.
		$response = $apiPage->index(new ServerRequest(uri: '/__fileRouting', method: 'GET'));
		$this->assertSame(200, $response->getStatusCode());

		$payload = json_decode((string) $response->getBody(), true);
		$paths = array_column($payload['result'], 'path');
		$this->assertContains('home.php', $paths);
		$this->assertContains('blog/post.php', $paths);

		foreach ($paths as $returnedPath) {
			$this->assertStringNotContainsString(realpath($this->projectDir), $returnedPath);
			$this->assertFalse(str_starts_with($returnedPath, '/'));
		}

		// Valid subdirectory lists relative paths under pagesPath.
		$subResponse = $apiPage->index(
			(new ServerRequest(uri: '/__fileRouting', method: 'GET'))
				->withQueryParams(['location' => 'blog'])
		);
		$this->assertSame(200, $subResponse->getStatusCode());
		$subPayload = json_decode((string) $subResponse->getBody(), true);
		$this->assertSame(['blog/post.php'], array_column($subPayload['result'], 'path'));

		// Traversal & absolute paths must be blocked with 403.
		$traversalLocations = [
			'..',
			'../',
			'blog/../../',
			'..\\',
			'blog\\..\\..',
			'/etc',
			'C:\\Windows',
			'%2e%2e',
			'blog/%2e%2e/..',
			'.',
			"blog\0",
		];

		foreach ($traversalLocations as $badLocation) {
			$res = $apiPage->index(
				(new ServerRequest(uri: '/__fileRouting', method: 'GET'))
					->withQueryParams(['location' => $badLocation])
			);
			$this->assertSame(
				403,
				$res->getStatusCode(),
				sprintf('Expected 403 for location "%s", got %d', $badLocation, $res->getStatusCode())
			);
		}

		// Non-string location parameter returns 400.
		$arrayRes = $apiPage->index(
			(new ServerRequest(uri: '/__fileRouting', method: 'GET'))
				->withQueryParams(['location' => ['blog']])
		);
		$this->assertSame(400, $arrayRes->getStatusCode());

		// Nonexistent valid location returns 404.
		$missingRes = $apiPage->index(
			(new ServerRequest(uri: '/__fileRouting', method: 'GET'))
				->withQueryParams(['location' => 'does-not-exist'])
		);
		$this->assertSame(404, $missingRes->getStatusCode());
	}

	public function testFileRouterRejectsInvalidHttpMethods(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'about.php', '<div>about</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'index.php', '<div>index</div>');

		$router = new FileRouter(new FileRoutingConfig(['pagesPath' => $pagesDir]), '/');

		foreach (['OPTIONS', 'HEAD', 'TRACE', 'CONNECT', 'CUSTOM'] as $method) {
			$aboutResult = $router->match(new ServerRequest(uri: '/about', method: $method));
			$this->assertTrue($aboutResult->isFailure(), "Expected {$method} /about to fail");

			$rootResult = $router->match(new ServerRequest(uri: '/', method: $method));
			$this->assertTrue($rootResult->isFailure(), "Expected {$method} / to fail");
		}
	}

	public function testFileRouterNormalizesRootBasePathAndStripsOnlyLeadingPrefix(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'index.php', '<div>root</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c.php', '<div>nested</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'profile.php', '<div>app-profile</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'profile.php', '<div>root-profile</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'lication.php', '<div>lication</div>');

		$config = new FileRoutingConfig(['pagesPath' => $pagesDir]);

		// Root basePath "/" (and null in CLI where detectBaseUrl() returns "/") must NOT strip slashes.
		$rootSlashRouter = new FileRouter($config, '/');
		$nestedResult = $rootSlashRouter->match(new ServerRequest(uri: '/a/b/c', method: 'GET'));
		$this->assertFalse($nestedResult->isFailure());
		$this->assertSame(
			realpath($pagesDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c.php'),
			$nestedResult->get('_fileController')
		);

		$defaultNullRouter = new FileRouter($config, null);
		$nestedNullResult = $defaultNullRouter->match(new ServerRequest(uri: '/a/b/c', method: 'GET'));
		$this->assertFalse($nestedNullResult->isFailure());
		$this->assertSame(
			realpath($pagesDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c.php'),
			$nestedNullResult->get('_fileController')
		);

		// Non-root basePath "/app/" must strip ONLY the leading "/app" prefix on a segment boundary.
		$subpathRouter = new FileRouter($config, '/app/');

		$repeatedPrefixResult = $subpathRouter->match(new ServerRequest(uri: '/app/app/profile', method: 'GET'));
		$this->assertFalse($repeatedPrefixResult->isFailure());
		$this->assertSame(
			realpath($pagesDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'profile.php'),
			$repeatedPrefixResult->get('_fileController')
		);

		// Partial segment prefix "/application" must NOT match "lication.php".
		$partialPrefixResult = $subpathRouter->match(new ServerRequest(uri: '/application', method: 'GET'));
		$this->assertTrue($partialPrefixResult->isFailure());

		// Base path exact match "/app" and "/app/" resolve to root index.php.
		$baseExact = $subpathRouter->match(new ServerRequest(uri: '/app', method: 'GET'));
		$this->assertFalse($baseExact->isFailure());
		$this->assertSame(realpath($pagesDir . DIRECTORY_SEPARATOR . 'index.php'), $baseExact->get('_fileController'));

		$baseTrailing = $subpathRouter->match(new ServerRequest(uri: '/app/', method: 'GET'));
		$this->assertFalse($baseTrailing->isFailure());
		$this->assertSame(realpath($pagesDir . DIRECTORY_SEPARATOR . 'index.php'), $baseTrailing->get('_fileController'));
	}

	public function testFileRouterBlocksTraversalAndEnforcesPagesPathContainment(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'index.php', '<div>index</div>');
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'ok.php', '<div>ok</div>');
		$this->writeFile($this->projectDir . DIRECTORY_SEPARATOR . 'outside.php', '<?php echo "secret";');
		// Sibling file matching "{$pagesDir}.php" must never be matched for "/".
		$this->writeFile($this->projectDir . DIRECTORY_SEPARATOR . 'pages.php', '<?php echo "sibling";');

		$config = new FileRoutingConfig(['pagesPath' => $pagesDir]);
		$router = new FileRouter($config, '/');

		$maliciousPaths = [
			'/../outside',
			'/sub/../../outside',
			'/..\\outside',
			'/sub/..\\..\\outside',
			'/./index',
			'/%2e%2e/outside',
			'/%2e%2e%5coutside',
			'/outside%00',
			'http://example.com//outside',
		];

		foreach ($maliciousPaths as $maliciousPath) {
			$result = $router->match(new ServerRequest(uri: $maliciousPath, method: 'GET'));
			$this->assertTrue($result->isFailure(), "Expected traversal path '{$maliciousPath}' to fail");
		}

		// Direct fileExists() containment check even if caller passes a traversal path.
		$this->assertNull($router->fileExists($pagesDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'outside', 'get'));

		// Removing pages/index.php must NOT fall back to sibling pages.php outside pagesDir.
		unlink($pagesDir . DIRECTORY_SEPARATOR . 'index.php');
		$rootResult = $router->match(new ServerRequest(uri: '/', method: 'GET'));
		$this->assertTrue($rootResult->isFailure());
	}

	public function testFileRouterHandlesMissingPagesDirectoryAndUnmatchedSlugMethodWithoutError(): void
	{
		$missingRouter = new FileRouter(
			new FileRoutingConfig(['pagesPath' => $this->projectDir . DIRECTORY_SEPARATOR . 'missing-pages']),
			'/'
		);
		$this->assertTrue($missingRouter->match(new ServerRequest(uri: '/anything', method: 'GET'))->isFailure());

		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$this->writeFile($pagesDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '[id].post.php', '<?php $id = 1;');

		$router = new FileRouter(new FileRoutingConfig(['pagesPath' => $pagesDir]), '/');

		$getResult = $router->match(new ServerRequest(uri: '/items/42', method: 'GET'));
		$this->assertTrue($getResult->isFailure());

		$postResult = $router->match(new ServerRequest(uri: '/items/42', method: 'POST'));
		$this->assertFalse($postResult->isFailure());
		$this->assertSame(['id' => '42'], $postResult->getMatchedParams());
	}

	public function testFileRoutingCacheHandlesPurePhpFilesWithoutClosingTagAndWritesAtomically(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$cacheDir = $this->projectDir . DIRECTORY_SEPARATOR . 'cache';
		$purePhpFile = $pagesDir . DIRECTORY_SEPARATOR . 'api.php';

		$purePhpSource = <<<'PHP'
<?php

declare(strict_types=1);

$secretToken = 'never-leak-into-template';

return ['ok' => true];
PHP;
		$this->writeFile($purePhpFile, $purePhpSource);

		$config = new FileRoutingConfig([
			'pagesPath' => $pagesDir,
			'cachePath' => $cacheDir,
		]);
		$cache = new FileRoutingCache($config, new ViewConfig());

		// split() keeps all PHP code in $php and leaves $template empty.
		[$php, $template] = $cache->split($purePhpSource);
		$this->assertSame(trim($purePhpSource), $php);
		$this->assertSame('', $template);

		// Template-only content without <?php leaves $php empty.
		[$noPhp, $htmlOnly] = $cache->split('<div>plain html</div>');
		$this->assertSame('', $noPhp);
		$this->assertSame('<div>plain html</div>', $htmlOnly);

		// generate() / get() writes controller and empty template atomically with no leftover .tmp files.
		[$controllerPath, $templatePath, $lang] = $cache->get($purePhpFile);

		$this->assertNotNull($controllerPath);
		$this->assertFileExists($controllerPath);
		$this->assertSame(trim($purePhpSource), file_get_contents($controllerPath));
		$this->assertFileExists($templatePath);
		$this->assertSame('', file_get_contents($templatePath));
		$this->assertNull($lang);

		// Overwrite source and re-generate to test atomic replacement.
		$updatedSource = "<?php\n\n\$version = 2;\n";
		file_put_contents($purePhpFile, $updatedSource);
		touch($purePhpFile, time() + 2);

		$cache->get($purePhpFile);
		$this->assertSame(trim($updatedSource), file_get_contents($controllerPath));

		$tmpFiles = glob($cacheDir . DIRECTORY_SEPARATOR . '*.tmp') ?: [];
		$this->assertSame([], $tmpFiles);
	}

	public function testMainPageCleansOutputBufferOnExceptionAndDoesNotLeakLayoutAcrossRequests(): void
	{
		$pagesDir = $this->projectDir . DIRECTORY_SEPARATOR . 'pages';
		$cacheDir = $this->projectDir . DIRECTORY_SEPARATOR . 'cache';

		$this->writeFile($cacheDir . DIRECTORY_SEPARATOR . 'default.phtml', '<default><?= $this->section("content") ?></default>');
		$this->writeFile($cacheDir . DIRECTORY_SEPARATOR . 'custom.phtml', '<custom><?= $this->section("content") ?></custom>');

		$this->writeFile(
			$pagesDir . DIRECTORY_SEPARATOR . 'custom-page.php',
			<<<'PHP'
<?php

$this->setLayout('custom');
$message = 'custom-body';

?>
<p><?= $message ?></p>
PHP
		);

		$this->writeFile(
			$pagesDir . DIRECTORY_SEPARATOR . 'default-page.php',
			<<<'PHP'
<?php

$message = 'default-body';

?>
<p><?= $message ?></p>
PHP
		);

		$this->writeFile(
			$pagesDir . DIRECTORY_SEPARATOR . 'throwing-page.php',
			<<<'PHP'
<?php

$this->setLayout('custom');
echo 'buffered output before error';
ob_start();
echo 'nested buffered output';
throw new RuntimeException('boom');
PHP
		);

		$config = new FileRoutingConfig([
			'pagesPath' => $pagesDir,
			'cachePath' => $cacheDir,
		]);
		$viewConfig = $this->createViewConfig($cacheDir);
		$engine = new Engine($cacheDir, 'phtml');
		$engine->addFolder('filerouting', $cacheDir);
		$engine->addFolder('overnight', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'templates');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(function (string $class) use ($viewConfig, $engine) {
				if ($class === PlatesRenderer::class) {
					return new PlatesRenderer($viewConfig, $engine, $this->createMock(Application::class));
				}

				return null;
			});

		$router = new FileRouter($config, '/');
		$page = new MainPage(
			new ViewManager($viewConfig, $container, new RequestStack()),
			$this->createMock(RouterInterface::class),
			$viewConfig,
			$config,
			$container
		);

		// Request 1 uses custom layout.
		$req1 = new ServerRequest(uri: '/custom-page', method: 'GET');
		$req1 = $req1->withAttribute(RouteResult::class, $router->match($req1));
		$res1 = $page->index($req1);
		$this->assertInstanceOf(ViewResult::class, $res1);
		$html1 = (string) $page->successView($res1->toArray())->getBody();
		$this->assertSame('<custom><p>custom-body</p></custom>', $html1);

		// Request 2 on the same shared MainPage instance must still use the default layout.
		$req2 = new ServerRequest(uri: '/default-page', method: 'GET');
		$req2 = $req2->withAttribute(RouteResult::class, $router->match($req2));
		$res2 = $page->index($req2);
		$this->assertInstanceOf(ViewResult::class, $res2);
		$html2 = (string) $page->successView($res2->toArray())->getBody();
		$this->assertSame('<default><p>default-body</p></default>', $html2);

		// Request 3 throws after setting layout and opening nested output buffers.
		$obLevelBefore = ob_get_level();
		$req3 = new ServerRequest(uri: '/throwing-page', method: 'GET');
		$req3 = $req3->withAttribute(RouteResult::class, $router->match($req3));

		$caught = null;

		try {
			$page->index($req3);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught);
		$this->assertSame('boom', $caught->getMessage());
		$this->assertSame($obLevelBefore, ob_get_level());

		// Request 4 after the exception still uses the default layout.
		$res4 = $page->index($req2);
		$this->assertInstanceOf(ViewResult::class, $res4);
		$html4 = (string) $page->successView($res4->toArray())->getBody();
		$this->assertSame('<default><p>default-body</p></default>', $html4);
	}

	private function indexRoutesByName(RouterConfig $routerConfig): array
	{
		$routes = [];
		foreach ($routerConfig->getRoutesToInject() as $route) {
			$routes[$route->getName()] = $route;
		}
		foreach ($routerConfig->getRoutes() as $route) {
			$routes[$route->getName()] = $route;
		}

		return $routes;
	}

	private function createApplication(?bool $exposeApi): Application
	{
		$configDir = $this->projectDir . DIRECTORY_SEPARATOR . 'config';
		if (! is_dir($configDir)) {
			mkdir($configDir, 0755, true);
		}

		$this->writeFile($this->projectDir . DIRECTORY_SEPARATOR . '.env', "APP_DEBUG=true\nAPP_ENV=testing\n");

		$exposeLine = $exposeApi === null
			? ''
			: '$fileRouting->set(\'exposeApi\', ' . ($exposeApi ? 'true' : 'false') . ');';

		$this->writeFile(
			$configDir . DIRECTORY_SEPARATOR . 'file-routing.all.php',
			<<<PHP
<?php

use ON\FileRouting\FileRoutingConfig;

\$fileRouting = new FileRoutingConfig();
\$fileRouting->set('cachePath', 'var/cache/filerouting');
{$exposeLine}

return \$fileRouting;
PHP
		);

		return new Application([
			'paths' => [
				'project' => $this->projectDir,
			],
			'extensions' => [
				ConfigExtension::class => [],
				ContainerExtension::class => [],
				RouterExtension::class => [],
				FileRoutingExtension::class => [],
			],
			'debug' => true,
		]);
	}

	private function createViewConfig(string $cachePath): ViewConfig
	{
		return new ViewConfig([
			'templates' => [
				'extension' => 'phtml',
				'paths' => [
					$cachePath,
					'filerouting' => $cachePath,
					'overnight' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'templates',
				],
			],
			'formats' => [
				'html' => [
					'default' => 'default',
					'layouts' => [
						'default' => [
							'renderer' => 'plates',
							'sections' => [],
						],
						'custom' => [
							'renderer' => 'plates',
							'sections' => [],
						],
					],
					'renderers' => [
						'plates' => [
							'class' => PlatesRenderer::class,
							'extension' => 'phtml',
							'inject' => [],
						],
					],
				],
			],
		]);
	}

	private function writeFile(string $path, string $content): void
	{
		$dir = dirname($path);
		if (! is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($path, $content);
	}

	private function createProjectDir(): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'overnight-filerouting-sec-' . bin2hex(random_bytes(8));
		mkdir($dir, 0755, true);

		return $dir;
	}

	private function removeDirectory(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($dir);
	}
}
