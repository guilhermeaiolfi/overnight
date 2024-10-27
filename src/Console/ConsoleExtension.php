<?php
namespace ON\Console;

use Exception;
use ON\Application;
use ON\Console\Command\ClearCacheCommand;
use ON\Extension\AbstractExtension;
use Psr\Log\LoggerInterface;


use ON\Console\Command\Command;

use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use ON\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\LazyCommand;
use ON\Console\Command\ListCommand;
use ON\Console\Command\OvernightCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;

use ON\Console\ConsoleEvents;
use ON\Console\Event\ConsoleCommandEvent;
use ON\Console\Event\ConsoleErrorEvent;
use ON\Console\Event\ConsoleSignalEvent;
use ON\Console\Event\ConsoleTerminateEvent;
use ON\Container\Executor\Executor;
use ON\Container\Executor\ExecutorInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputAwareInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

class ConsoleExtension extends AbstractExtension {
  
    protected array $pendingTasks = [ 'init' ];

    protected ?ConsoleApplication $consoleApp = null;

    public static function install(Application $app, ?array $options = []): mixed {
        $extension = new self($app, $options);
        return $extension;
    }

    public function __construct (
        protected Application $app, 
        protected array $options
    )
    {
    }

    public function requires(): array
    {
        return [];   
    }

    public function setup($counter): bool
    {
        $this->app->registerMethod("run", [$this, "run"]);
        $this->app->console = $this;
        $this->consoleApp = new ConsoleApplication();

        if ($this->removePendingTask('init')) {
          
        }
        

        if ($this->hasPendingTasks()) {
            return false;
        }
        return true;
    }

    public function run ()
    {
        $this->consoleApp->add($this->app->container->get(ClearCacheCommand::class));

        $command = new OvernightCommand();

        $executor = $this->app->container->get(ExecutorInterface::class);
        $command
            ->setName("on:test")
            ->setAction("App\\Page\\FooPage::command")
            ->setDescription("To test running an overnight command")
            ->setExecutor($executor);
        $this->consoleApp->add($command);
        $this->consoleApp->run();
    }

}