<?php

declare(strict_types=1);

namespace ON\Maintenance;

use ON\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
				new InputArgument('onoff', InputArgument::REQUIRED, 'ON/OFF', null, ['on', 'off']),
			])
			->setDescription('Turn ON/OFF the maintenance mode');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
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
		if ($data === []) {
			return;
		}

		$envFile = '.env';
		$lines = is_file($envFile) ? (file($envFile) ?: []) : [];
		$updated = [];
		foreach ($lines as $index => $line) {
			if (preg_match('/^[ \t]*(?:export[ \t]+)?([a-z_][a-z0-9_]*)[ \t]*=/i', $line, $matches) !== 1) {
				continue;
			}

			$key = $matches[1];
			if (array_key_exists($key, $data)) {
				$lines[$index] = $key . "={$data[$key]}\n";
				$updated[$key] = true;
			}
		}

		$content = implode('', $lines);
		$remaining = array_diff_key($data, $updated);
		if ($remaining !== [] && $content !== '' && ! str_ends_with($content, "\n")) {
			$content .= "\n";
		}
		foreach ($remaining as $key => $value) {
			$content .= $key . "={$value}\n";
		}

		file_put_contents($envFile, $content);
	}
}
