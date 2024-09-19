<?php
namespace ON\Extension;

use Clockwork\Support\Vanilla\Clockwork;
use Exception;
use ON\Application;
use ON\Benchmark;
use ON\Clockwork\DataSource\PsrLoggerDatasource;
use ON\Event\EventSubscriberInterface;
use ON\Extension\AbstractExtension;
use Psr\Log\LoggerInterface;

use function clock;

class ClockworkExtension extends AbstractExtension implements EventSubscriberInterface {

    protected Clockwork $clockwork;

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        return $extension;
    }

    public function __construct (
        protected Application $app, 
        protected array $options
    )
    {
        if (!function_exists('clock'))
        {
            $file = $options["config_file"];
            if (!file_exists($file)) {
                $class= self::class;
                throw new Exception("File ({$file}) doesn't exist for {$class}.");
            }

            $config = require_once($file);

            $settings = $config["clockwork"];
    
            $this->clockwork = Clockwork::init($settings);
        }

        clock()->event("Booting")->begin();
    }

    public function setup(int $counter): bool
    {
        if ($this->app->isExtensionReady('container')) {
            $container = Application::getContainer();
            $container->set(Clockwork::class, $this->clockwork);
            $logger = $container->get(LoggerInterface::class);
            $loggerDataSource = new PsrLoggerDatasource($logger);
            $this->clockwork->addDataSource($loggerDataSource);
            return true;
        }
        return false;
    }
    public function ready()
    {
        clock()->event('Booting')->end();

    }
    public function onQuery($event)
    {
        $query = $event->getQuery();

        $filter = \Clockwork\Helpers\StackFilter::make()
            ->isNotVendor([ 'itsgoingd', 'guilhermeaiolfi', 'league' ])
			      ->isNotNamespace([ 'Clockwork', 'League', 'Invoker' ])
            ->isNotFunction([ 'profileCall', 'emitEvent' ]);

        $trace = \Clockwork\Helpers\StackTrace::get()->resolveViewName()->skip($filter);

        clock()->addDatabaseQuery(
            $query->getSql(), 
            $query->getParameters(), 
            floor($query->getDuration() * 1000),
            [
                "trace" => (new \Clockwork\Helpers\Serializer)->trace($trace)
            ]
        );
    }

    public function onManagerCreate($event)
    {
        $container = Application::getContainer();
        $database = $event->getSubject();

        $config = $container->get('config');
        
        if ($database->getName() == $config["db"]["default"]) {
            $database->getConnection()->setEventDispatcher($container->get(\Psr\EventDispatcher\EventDispatcherInterface::class));
        }
    }

    /*public function onInit($event)
    {
        clock()->info("Booting:start");
        clock()->event('Booting')->begin();
    }*/

    public function onRun($event)
    {
        clock()->event('Run')->begin();
        $this->showBenchmarkTable();
    }
    
    public function onEnd($event)
    {
        clock()->event('Run')->end();

    }

    public static function getSubscribedEvents(): array {
        return [
            "pdo.query" => 'onQuery',
            "core.db.manager.create" => 'onManagerCreate',
            //"core.init" => "onInit", // it was already emmited
            "core.run" => "onRun",
            "core.end" => "onEnd"
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
                "Time(ms)" => number_format($time, 2)
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