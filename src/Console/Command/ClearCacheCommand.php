<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ON\Console\Command;

use ON\Cache\CacheInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class ClearCacheCommand extends Command
{

    public function __construct(
        protected CacheInterface $cache
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();

        $this
            ->setName('cache:clear')
            ->setDefinition([
                new InputArgument('command_name', InputArgument::OPTIONAL, 'The command name', 'help', fn () => array_keys((new ApplicationDescription($this->getApplication()))->getCommands())),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt', fn () => (new DescriptorHelper())->getFormats()),
                new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command help'),
            ])
            ->setDescription('Clear Cache');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$helper = $this->getHelper('question');

        //$question = new ConfirmationQuestion('Are you sure?', false);

        $io = new SymfonyStyle($input, $output);
        $formatter = new FormatterHelper();

        //$choices = $io->choice("Which ones?", [ "One", "Two" ], null, true);
        if ($io->confirm($input, false)) {
            $this->cache->clear();

            $output->write($formatter->formatBlock("OK", "Cache cleared!"));
            $io->success("Cache cleared!");
            return Command::SUCCESS;
        }

        return 0;
    }
}
