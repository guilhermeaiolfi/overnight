<?php

namespace ON\Extension;

use Exception;
use ON\Application;

use ON\Config\ConfigBuilder;
use Adbar\Dot;
use ON\Config\ConfigInterface;
use ON\Config\OwnParameterPostProcessor;
use ON\Config\Scanner\Scanner;
use ON\Config\Scanner\TypeDefinition;
use ON\Discovery\DiscoverClassInterface;
use ON\Discovery\DiscoverFileInterface;
use ON\Discovery\RouteDiscovery;
use Symfony\Component\Finder\Finder;

class DiscoveryExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    
    protected array $discovers = [];
    protected array $pendingProcess = [];

    protected array $pendingTasks = ['discovery:setup'];

    protected array $files;
    public function __construct(
        protected Application $app,
        protected array $options = []
    ) {
        $this->discovers[] = new RouteDiscovery($app);
        //$this->scanner->scanFinder($finder);
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if ($this->hasPendingTask("discovery:setup")) {
            $discovers = $this->discovers;

            foreach ($discovers as $discover) {
                $timestamp = $discover->cachedTimestamp();
                if ($timestamp > 0) {
                    $discover->recover();
                }

                if ($this->app->isDebug()) {
                    if ($discover instanceof DiscoverFileInterface) {
                        $finder = new Finder();
                        $finder->files()->in("src/")->date(">= " . date("d.m.Y H:i:s", $timestamp));
                        $discover->updateFiles($finder);
                    }

                    if ($discover instanceof DiscoverClassInterface) {

                        $scanner = new Scanner();
                        $scanner->allowAutoloading(true);
                        $finder = new Finder();
                        $files = $finder->files()->in("src/")->name("*.php")->date(">= " . date("d.m.Y H:i:s", $timestamp));
                        $scanner->scanFinder($files);
                        $classes = $scanner->getClasses(TypeDefinition::TYPE_CLASS);
                        $definitions = $scanner->getDefinitions($classes);

                        $discover->updateClasses($definitions);
                    }
                }
            }

            foreach ($discovers as $discover) {
                $discover->save();
                if (!$discover->process()) {
                    $this->pendingProcess[] = $discover;
                }
            }
            $this->removePendingTask("discovery:setup");
        } else {
            foreach ($this->pendingProcess as $discover) {
                if ($discover->process()) {
                    $index = array_search($discover, $this->pendingProcess);
                    array_splice($this->pendingProcess, $index, 1);
                }
            }
        }

        if (count($this->pendingProcess) == 0) {
            return true;
        }


        return false;
    }

    public function ready() {
       
    }
}
