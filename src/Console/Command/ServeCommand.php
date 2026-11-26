<?php

declare(strict_types=1);

namespace ON\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ServeCommand extends Command
{
	public function __construct(
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('serve')
			->setDefinition([
				new InputOption('host', null, InputOption::VALUE_REQUIRED, 'Host', 'localhost'),
				new InputOption('port', null, InputOption::VALUE_REQUIRED, 'Port', 8000),
				new InputOption('publicDir', null, InputOption::VALUE_REQUIRED, 'Public Directoy (where index.php is at)', "public/"),
			])
			->setDescription('Start a PHP built-in development server');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$host = trim((string) $input->getOption("host"));
		$port = $input->getOption("port");
		$publicDir = (string) $input->getOption("publicDir");

		$bindHost = $this->validateHost($host);
		if ($bindHost === null) {
			$io->error(sprintf('Invalid host "%s".', $host));

			return Command::FAILURE;
		}

		$portNum = $this->validatePort($port);
		if ($portNum === null) {
			$io->error(sprintf('Invalid port "%s". Must be an integer between 1 and 65535.', (string) $port));

			return Command::FAILURE;
		}

		if ($publicDir === '' || str_contains($publicDir, "\0") || ! is_dir($publicDir)) {
			$io->error(sprintf('Invalid public directory "%s". Directory does not exist.', $publicDir));

			return Command::FAILURE;
		}

		$command = [PHP_BINARY, '-S', "{$bindHost}:{$portNum}", '-t', $publicDir];
		$descriptors = defined('STDIN')
			? [0 => STDIN, 1 => STDOUT, 2 => STDERR]
			: [];

		$process = proc_open($command, $descriptors, $pipes);
		if (! is_resource($process)) {
			$io->error('Failed to start PHP built-in development server.');

			return Command::FAILURE;
		}

		$exitCode = proc_close($process);

		return $exitCode >= 0 ? $exitCode : Command::FAILURE;
	}

	private function validateHost(string $host): ?string
	{
		if ($host === '' || preg_match('/[\s\x00-\x1f]/', $host)) {
			return null;
		}

		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$unwrapped = substr($host, 1, -1);

			return filter_var($unwrapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $host : null;
		}

		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			return "[{$host}]";
		}

		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return $host;
		}

		if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
			return $host;
		}

		return null;
	}

	private function validatePort(mixed $port): ?int
	{
		if (is_int($port)) {
			$portNum = $port;
		} elseif (is_string($port) && ctype_digit($port)) {
			$portNum = (int) $port;
		} else {
			return null;
		}

		if ($portNum < 1 || $portNum > 65535) {
			return null;
		}

		return $portNum;
	}
}
