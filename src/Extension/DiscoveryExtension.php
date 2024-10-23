<?php

namespace ON\Extension;

use Exception;
use ON\Application;

use ON\Config\ConfigBuilder;
use Adbar\Dot;
use ON\Config\AppConfig;
use ON\Config\ConfigInterface;
use ON\Config\OwnParameterPostProcessor;
use ON\Config\Scanner\Scanner;
use ON\Config\Scanner\TypeDefinition;
use ON\Discovery\ClassFinder;
use ON\Discovery\DiscoverClassInterface;
use ON\Discovery\DiscoverFileInterface;
use ON\Discovery\RouteDiscovery;
use Symfony\Component\Finder\Finder;

class DiscoveryExtension extends AbstractExtension
{
    protected int $type = self::TYPE_EXTENSION;
    
    protected array $discovers = [];
    protected array $pendingProcess = [];

    protected array $pendingTasks = [ 'config:ready', 'discovery:setup' ];

    public ClassFinder $classFinder;
    protected array $files;
    protected AppConfig $appCfg;
    public function __construct(
        protected Application $app,
        protected array $options = []
    ) {
        $this->classFinder = new ClassFinder();
    }

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        return $extension;
    }

    public function setup(int $counter): bool
    {
        if ($this->hasPendingTask("config:ready")) {
            if ($this->app->isExtensionReady('config')) {
                $this->appCfg = $this->app->ext('config')->get(AppConfig::class);
                $this->removePendingTask('config:ready');
            }
        } else if ($this->hasPendingTask("discovery:setup")) {
            $this->discovers[] = new RouteDiscovery($this->app);
            $pattern = $this->appCfg->get('discovery.pattern');

            $discovers = $this->discovers;

            $oldest = 0;
            foreach ($discovers as $discover) {
                $timestamp = $discover->cachedTimestamp();
                if ($oldest == 0 || $oldest > $timestamp) {
                    $oldest = $timestamp;
                }
                if ($timestamp > 0) {
                    $discover->recover();
                }
            }
            clock()->event('discovery:finder')->begin();
            $finder = new Finder();
            
            $finder->files()->in($pattern)->date(">= " . date("d.m.Y H:i:s", $oldest));
            foreach ($finder as $file) {
                $timestamp = $discover->cachedTimestamp();
                if ($timestamp == 0 || $this->app->isDebug()) {
                    foreach ($discovers as $discover) {
                        if ($file->getMTime() > $timestamp) {
                            $discover->updateFile($file);
                        }
                    }
                }
            }
            clock()->event('discovery:finder')->end();

            clock()->event('discovery:save')->begin();
            foreach ($discovers as $discover) {
                $discover->save();
                if (!$discover->process()) {
                    $this->pendingProcess[] = $discover;
                }
            }
            clock()->event('discovery:save')->end();
            $this->removePendingTask("discovery:setup");
        } else {
            foreach ($this->pendingProcess as $discover) {
                if ($discover->process()) {
                    $index = array_search($discover, $this->pendingProcess);
                    array_splice($this->pendingProcess, $index, 1);
                }
            }
        }

        if (!$this->hasPendingTasks() && count($this->pendingProcess) == 0) {
            return true;
        }


        return false;
    }

    public function ready() {
       
    }
}
