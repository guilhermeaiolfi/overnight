<?php

declare(strict_types=1);

namespace ON\Console\Command;

use ON\Cache\CacheClearerDefinition;
use ON\Cache\CacheClearerRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class ClearCacheCommand extends Command
{
	public function __construct(
		protected CacheClearerRegistry $registry,
		protected ContainerInterface $container
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('cache:clear')
			->setDefinition([
				new InputArgument('clearers', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Cache clearer names'),
				new InputOption('all', null, InputOption::VALUE_NONE, 'Clear all registered caches'),
				new InputOption('list', null, InputOption::VALUE_NONE, 'List registered cache clearers'),
			])
			->setDescription('Clear Cache');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$formatter = new FormatterHelper();
		$definitions = $this->registry->all();

		if ($input->getOption('list')) {
			foreach ($definitions as $name => $definition) {
				$output->writeln(sprintf(
					'%s - %s%s',
					$name,
					$definition->label,
					$definition->description !== null ? ' (' . $definition->description . ')' : ''
				));
			}

			return Command::SUCCESS;
		}

		try {
			$selected = $this->resolveSelectedClearers($input, $output, $definitions);
		} catch (Throwable $e) {
			$output->writeln($formatter->formatSection('ERROR', $e->getMessage()));

			return Command::FAILURE;
		}

		if ($selected === []) {
			$io->warning('No cache clearers selected.');

			return Command::SUCCESS;
		}

		foreach ($selected as $definition) {
			try {
				$definition->clear($this->container, $output);
				$output->writeln($formatter->formatSection('OK', $definition->label . ' cleared!'));
			} catch (Throwable $e) {
				$output->writeln($formatter->formatSection('ERROR', sprintf(
					'Failed clearing %s: %s',
					$definition->name,
					$e->getMessage()
				)));

				return Command::FAILURE;
			}
		}

		return Command::SUCCESS;
	}

	/**
	 * @param array<string, CacheClearerDefinition> $definitions
	 * @return CacheClearerDefinition[]
	 */
	private function resolveSelectedClearers(InputInterface $input, OutputInterface $output, array $definitions): array
	{
		$names = $input->getArgument('clearers');

		if ($input->getOption('all')) {
			return array_values(array_filter(
				$definitions,
				fn (CacheClearerDefinition $definition): bool => $definition->includedInAll
			));
		}

		if ($names !== []) {
			$selected = [];
			foreach ($names as $name) {
				$selected[] = $this->registry->get($name);
			}

			return $selected;
		}

		$helper = $this->getHelper('question');
		$choices = [];
		foreach ($definitions as $name => $definition) {
			$choices[$name] = $definition->label;
		}
		$choices['all'] = 'All';

		$question = new ChoiceQuestion(
			'Please select the cache you want to remove',
			$choices,
			'all'
		);
		$question->setMultiselect(true);

		$selection = $helper->ask($input, $output, $question);

		if (in_array('all', $selection, true)) {
			return array_values(array_filter(
				$definitions,
				fn (CacheClearerDefinition $definition): bool => $definition->includedInAll
			));
		}

		$selected = [];
		foreach ($selection as $name) {
			$selected[] = $this->registry->get($name);
		}

		return $selected;
	}
}
