<?php

declare(strict_types=1);

namespace ON\Console\Command;

use ON\Application;
use ON\Cache\CacheInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class ClearCacheCommand extends Command
{
	public function __construct(
		protected CacheInterface $cache,
		protected Application $app
	) {
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

		$helper = $this->getHelper('question');
		$question = new ChoiceQuestion(
			'Please select the cache you want to remove',
			[
				'1' => 'Cache (from CacheInterface)',
				'2' => 'Discovery',

				'a' => 'All',
			],
			'a'
		);
		$question->setMultiselect(true);

		$selection = $helper->ask($input, $output, $question);

		if (in_array('a', $selection) || in_array('1', $selection)) {
			$this->cache->clear();
			$output->writeln($formatter->formatSection("OK", "CacheInterface cleared!"));
		}

		if (in_array('a', $selection) || in_array('2', $selection)) {
			$this->cache->clear();
			if ($this->app->hasExtension('discovery')) {
				$this->app->discovery->forget();
			}
			$output->writeln($formatter->formatSection("OK", "Discovery Cache cleared!"));
		}






		return Command::SUCCESS;
	}
}
