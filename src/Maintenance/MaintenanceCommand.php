<?php

declare(strict_types=1);

namespace ON\Maintenance;

use ON\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MaintenanceCommand extends Command
{
	public function __construct(
		protected Application $app
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('on:maintenance')
			->setDefinition([
				new InputArgument('onoff', InputArgument::REQUIRED, 'ON/OFF', null, fn () => array_keys((new ApplicationDescription($this->getApplication()))->getCommands())),
			])
			->setDescription('Turn ON/OFF the maintenance mode');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		//$io = new SymfonyStyle($input, $output);
		$formatter = new FormatterHelper();

		$onoff = $input->getArgument("onoff");

		if ($onoff == "on") {
			$this->app->env->populate(["APP_MAINTENANCE" => true], true);
			$this->updateEnv(["APP_MAINTENANCE" => "true"]);
			$output->write($formatter->formatSection("OK", "Maintenance set to ON!"));

			return Command::SUCCESS;
		} elseif ($onoff == "off") {
			$this->updateEnv(["APP_MAINTENANCE" => "false"]);
			$output->write($formatter->formatSection("OK", "Maintenance set to OFF!"));

			return Command::SUCCESS;
		}

		return Command::INVALID;
	}

	public function updateEnv(array $data = []): void
	{
		if (! count($data)) {
			return;
		}

		$pattern = '/([^\=]*)\=[^\n]*/';

		$envFile = '.env';
		$lines = file($envFile);
		$newLines = [];
		foreach ($lines as $line) {
			preg_match($pattern, $line, $matches);

			$key = trim($matches[1]);

			if (! count($matches)) {
				$newLines[] = $line;

				continue;
			}

			if (! key_exists($key, $data)) {
				$newLines[] = $line;

				continue;
			}

			$line = $key . "={$data[$key]}\n";
			$newLines[] = $line;
			if (isset($data[$key])) {
				unset($data[$key]);
			}
		}

		foreach ($data as $key => $value) {
			$newLines[] = $key . "={$value}\n";
		}

		$newContent = implode('', $newLines);
		file_put_contents($envFile, $newContent);
	}
}
