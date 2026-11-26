<?php

declare(strict_types=1);

namespace ON\DB\Cycle;

use function array_shift;
use function count;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function htmlspecialchars;
use const PHP_SAPI;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use function str_contains;
use function str_starts_with;
use Stringable;
use function strtolower;

class Logger implements LoggerInterface //, \DebugBar\DataCollector\DataCollectorInterface
{
	use LoggerTrait;

	private bool $display = false;

	private int $countWrites = 0;
	private int $countReads = 0;

	/** @var list<string> */
	protected array $messages = [];

	protected int $maxMessages;

	public function __construct(int $maxMessages = 1000)
	{
		$this->maxMessages = $maxMessages;
	}

	public function getName(): string
	{
		return "CycleORM";
	}

	public function collect(): array
	{
		$messages = $this->messages;

		return [
			'count' => count($messages),
			'messages' => $messages,
		];
	}

	public function clearMessages(): void
	{
		$this->messages = [];
	}

	public function countWriteQueries(): int
	{
		return $this->countWrites;
	}

	public function countReadQueries(): int
	{
		return $this->countReads;
	}

	public function log($level, string|Stringable $message, array $context = []): void
	{
		$message = (string) $message;

		if (! empty($context['elapsed'])) {
			$sql = strtolower($message);
			if (
				str_starts_with($sql, 'insert') ||
				str_starts_with($sql, 'update') ||
				str_starts_with($sql, 'delete')
			) {
				$this->countWrites++;
			} else {
				if (! $this->isPostgresSystemQuery($sql)) {
					$this->countReads++;
				}
			}
		}

		if (! $this->display) {
			return;
		}

		$output = $this->formatMessageForOutput($message);
		$lineBreak = $this->isCli() ? "\n" : " <br />\n";

		if ($level === LogLevel::ERROR) {
			$this->recordMessage($message);
			echo $lineBreak . "! \033[31m" . $output . "\033[0m";
		} elseif ($level === LogLevel::ALERT) {
			$this->recordMessage($message);
			echo $lineBreak . "! \033[35m" . $output . "\033[0m";
		} elseif (str_starts_with($message, 'SHOW')) {
			echo $lineBreak . "> \033[34m" . $output . "\033[0m";
		} else {
			if ($this->isPostgresSystemQuery($message)) {
				$this->recordMessage($message);
				echo $lineBreak . "> \033[90m" . $output . "\033[0m";

				return;
			}

			if (str_starts_with($message, 'SELECT')) {
				$this->recordMessage($message);
				echo $lineBreak . "> \033[32m" . $output . "\033[0m";
			} elseif (str_starts_with($message, 'INSERT')) {
				$this->recordMessage($message);
				echo $lineBreak . "> \033[36m" . $output . "\033[0m";
			} else {
				$this->recordMessage($message);
				echo $lineBreak . "> \033[33m" . $output . "\033[0m";
			}
		}
	}

	public function display(): void
	{
		$this->display = true;
	}

	public function hide(): void
	{
		$this->display = false;
	}

	protected function recordMessage(string $message): void
	{
		$this->messages[] = $message;

		if ($this->maxMessages > 0 && count($this->messages) > $this->maxMessages) {
			array_shift($this->messages);
		}
	}

	protected function formatMessageForOutput(string $message): string
	{
		if ($this->isCli()) {
			return $message;
		}

		return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	protected function isCli(): bool
	{
		return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
	}

	protected function isPostgresSystemQuery(string $query): bool
	{
		$query = strtolower($query);

		return str_contains($query, 'tc.constraint_name')
			|| str_contains($query, 'pg_indexes')
			|| str_contains($query, 'pg_constraint')
			|| str_contains($query, 'information_schema')
			|| str_contains($query, 'pg_class');
	}
}
