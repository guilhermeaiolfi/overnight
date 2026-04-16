<?php

declare(strict_types=1);

namespace ON\Clockwork;

use function clock;
use Clockwork\Helpers\Serializer;
use Clockwork\Helpers\StackFilter;
use Clockwork\Helpers\StackTrace;
use Clockwork\Support\Vanilla\Clockwork;
use Laminas\Stdlib\ArrayUtils;
use ON\Application;
use ON\Benchmark;
use ON\Clockwork\DataSource\PsrLoggerDatasource;
use ON\Clockwork\Middleware\ClockworkMiddleware;
use ON\Db\DatabaseConfig;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\Router\Router;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class ClockworkExtension extends AbstractExtension implements EventSubscriberInterface
{
	protected Clockwork $clockwork;

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);

		return $extension;
	}

	public function __construct(
		protected Application $app,
		protected array $options = []
	) {
		if (! function_exists('clock')) {
			$settings = ArrayUtils::merge($this->getOptions(), $options);
			$settings["api"] = Router::detectBaseUrl() . "/__clockwork/";
			$this->clockwork = Clockwork::init($settings);
			clock()->event("Booting")->begin();
		} else {
			$this->clockwork = clock();
		}

	}

	public function requires(): array
	{
		return [
			'container',
			'pipeline',
		];
	}

	public function getOptions(): array
	{
		return [
			//'api' => it is determined in the constructor, should be
			//         something like: '/onlegis/__clockwork/'
			'storage_files_path' => 'var/clockwork',
			'register_helpers' => true,
			'storage_expiration' => 2,
		];
	}

	public function boot(): void
	{
		// that's the order they happen
		//$this->app->ext('pipeline')->when('ready', [$this, 'onPipelineReady']);
		$this->app->ext('container')->when('ready', [$this, 'onContainerReady']);

		foreach ($this->app->getInstalledExtensions() as $extClassName) {
			clock()->event($extClassName)->begin();
			$this->app->ext($extClassName)->when('ready', function () use ($extClassName) {
				clock()->event($extClassName)->end();
			});
		}
	}

	public function setup(): void
	{
		$this->app->pipe("/", ClockworkMiddleware::class, 900);
		$this->setState("ready");
	}

	public function onContainerReady(): void
	{
		$container = $this->app->container;
		$container->set(Clockwork::class, $this->clockwork);
		$logger = $container->get(LoggerInterface::class);
		$loggerDataSource = new PsrLoggerDatasource($logger);
		$this->clockwork->addDataSource($loggerDataSource);
	}

	public function onQuery($event)
	{
		$query = $event->getQuery();

		$filter = StackFilter::make()
			->isNotVendor([ 'itsgoingd', 'guilhermeaiolfi', 'league' ])
				  ->isNotNamespace([ 'Clockwork', 'League', 'Invoker' ])
			->isNotFunction([ 'profileCall', 'emitEvent' ]);

		$trace = StackTrace::get()->resolveViewName()->skip($filter);

		clock()->addDatabaseQuery(
			$query->getSql(),
			$query->getParameters(),
			floor($query->getDuration() * 1000),
			[
				"trace" => (new Serializer())->trace($trace),
			]
		);
	}

	public function onManagerCreate($event)
	{
		$database = $event->getSubject();

		$config = $this->app->config->get(DatabaseConfig::class);

		if ($database->getName() == $config->get("default")) {
			// Set the event dispatcher on LoggingPDOStatement for query logging
			\ON\DB\DebugPDO\LoggingPDOStatement::setDispatcher(
				$this->app->container->get(EventDispatcherInterface::class)
			);
		}
	}

	/*public function onInit($event)
	{
		clock()->info("Booting:start");
		clock()->event('Booting')->begin();
	}*/

	public function onRun($event)
	{
		clock()->event('Booting')->end();
		clock()->event('Run')->begin();
		$this->showBenchmarkTable();
	}

	public function onEnd($event)
	{
		clock()->event('Run')->end();

	}

	public static function getSubscribedEvents(): array
	{
		return [
			"pdo.query" => 'onQuery',
			"core.db.manager.create" => 'onManagerCreate',
			//"core.init" => "onInit", // it was already emmited
			"core.run" => "onRun",
			"core.end" => "onEnd",

			/*"core.extensions.container.ready" => 'onContainerReady',
			"core.extensions.config.setup" => 'onConfigSetup',
			"core.extensions.pipeline.ready" => 'onPipelineReady',*/
		];
	}

	public function showBenchmarkTable()
	{
		$benchmark = clock()->userData('benchmark')
		->title('Benchmark');

		$values = [];
		$all = Benchmark::all();

		$totalTime = 0.0;
		foreach ($all as $title => $time) {
			$values[] = [
				"Title" => $title,
				"Time(ms)" => number_format($time, 2),
			];
			$totalTime += $time;
		}

		$benchmark->counters([
			'Benchmarks' => count($values),
			//'Total(ms)' => number_format($totalTime, 2)
		]);

		$benchmark->table('Benchmark', $values);
	}
}
