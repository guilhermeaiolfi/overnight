<?php

declare(strict_types=1);

namespace ON\Clockwork;

use Clockwork\Support\Vanilla\Clockwork;
use Laminas\Stdlib\ArrayUtils;
use Monolog\Logger;
use ON\Application;
use ON\Benchmark;
use ON\Clockwork\DataSource\ExtensionProfilerDataSource;
use ON\Clockwork\DataSource\PsrLoggerDataSource;
use ON\Clockwork\Middleware\ClockworkMiddleware;
use ON\Container\Init\Event\ContainerReadyEvent;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use ON\Init\Attribute\On;
use ON\Middleware\Init\Event\PipelineReadyEvent;
use ON\Middleware\PipelineExtension;
use ON\Router\Router;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ClockworkExtension extends AbstractExtension implements EventSubscriberInterface
{
	public const ID = 'clockwork';

	protected ?Clockwork $clockwork = null;
	protected ?ContainerInterface $container = null;

	public function __construct(
		Application $app,
		array $options = []
	) {
		parent::__construct($app, $options);
		if (! filter_var($options['force'] ?? $options['enable'] ?? $this->app->isDebug(), FILTER_VALIDATE_BOOLEAN)) {
			return;
		}

		$settings = ArrayUtils::merge($this->getOptions(), $options);
		$settings['api'] = rtrim(Router::detectBaseUrl(), '/') . '/__clockwork/';
		$this->clockwork = Clockwork::init($settings);
		$this->clockwork->event('Booting')->begin();
		$this->app->extensionProfiler()->setEnabled(true);
	}

	public function isActive(): bool
	{
		return $this->clockwork !== null;
	}

	public function getOptions(): array
	{
		return [
			//'api' => it is determined in the constructor, should be
			//         something like: '/onlegis/__clockwork/'
			'storage_files_path' => $this->app->paths->get('var')->append('clockwork')->getAbsolutePath(),
			'register_helpers' => true,
			'storage_expiration' => 2,
		];
	}

	#[On(optional: true)]
	public function onPipelineReady(PipelineReadyEvent $event): void
	{
		$this->injectMiddleware($event->pipeline);
	}

	public function injectMiddleware(?PipelineExtension $pipeline = null): void
	{
		if ($this->clockwork === null) {
			return;
		}

		($pipeline ?? $this->app->ext(PipelineExtension::class))->pipe("/", ClockworkMiddleware::class, 900);
	}

	#[On(optional: true)]
	public function onContainerReady(ContainerReadyEvent $event): void
	{
		if ($this->clockwork === null) {
			return;
		}

		$container = $this->container = $event->container;
		$container->set(Clockwork::class, $this->clockwork);
		if ($container->has(LoggerInterface::class)) {
			$logger = $container->get(LoggerInterface::class);
			if ($logger instanceof Logger) {
				$loggerDataSource = new PsrLoggerDataSource($logger);
				$this->clockwork->addDataSource($loggerDataSource);
			}
		}
		$this->clockwork->addDataSource(new ExtensionProfilerDataSource($this->app->extensionProfiler()));
	}

	/*public function onInit($event)
	{
		clock()->info("Booting:start");
		clock()->event('Booting')->begin();
	}*/

	public function onRun($event)
	{
		if ($this->clockwork === null) {
			return;
		}

		$this->clockwork->event('Booting')->end();
		$this->clockwork->event('Run')->begin();
		$this->showBenchmarkTable();
	}

	public function onEnd($event)
	{
		if ($this->clockwork === null) {
			return;
		}

		$this->clockwork->event('Run')->end();
	}

	public static function getSubscribedEvents(): array
	{
		return [
			//"core.init" => "onInit", // it was already emmited
			"core.run" => "onRun",
			"core.end" => "onEnd",

			/*"core.extensions.container.ready" => 'onContainerReady',
			"core.extensions.config.configure" => 'onConfigConfigure',
			"core.extensions.pipeline.ready" => 'onPipelineReady',*/
		];
	}

	public function showBenchmarkTable()
	{
		if ($this->clockwork === null) {
			return;
		}

		$benchmark = $this->clockwork->userData('benchmark')
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
