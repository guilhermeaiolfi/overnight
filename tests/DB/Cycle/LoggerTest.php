<?php

declare(strict_types=1);

namespace Tests\ON\DB\Cycle;

use ON\DB\Cycle\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LoggerTest extends TestCase
{
	public function testCollectBeforeAnyLogsReturnsEmptyArrayWithoutTypeError(): void
	{
		$logger = new Logger();

		$this->assertSame([
			'count' => 0,
			'messages' => [],
		], $logger->collect());
	}

	public function testCountsReadsAndWritesAndExcludesPostgresSystemQueries(): void
	{
		$logger = new Logger();

		$logger->log(LogLevel::INFO, 'SELECT * FROM users', ['elapsed' => 0.01]);
		$logger->log(LogLevel::INFO, 'INSERT INTO users (name) VALUES ("Alice")', ['elapsed' => 0.02]);
		$logger->log(LogLevel::INFO, 'UPDATE users SET name = "Bob"', ['elapsed' => 0.02]);
		$logger->log(LogLevel::INFO, 'DELETE FROM users WHERE id = 1', ['elapsed' => 0.01]);
		$logger->log(LogLevel::INFO, 'SELECT tc.constraint_name FROM information_schema.table_constraints tc', ['elapsed' => 0.01]);
		$logger->log(LogLevel::INFO, 'SELECT * FROM pg_indexes', ['elapsed' => 0.01]);
		$logger->log(LogLevel::INFO, 'SELECT * FROM pg_constraint', ['elapsed' => 0.01]);
		$logger->log(LogLevel::INFO, 'SELECT * FROM pg_class', ['elapsed' => 0.01]);

		$this->assertSame(1, $logger->countReadQueries());
		$this->assertSame(3, $logger->countWriteQueries());
	}

	public function testEscapesHtmlWhenOutputtingInWebSapi(): void
	{
		$logger = new class () extends Logger {
			protected function isCli(): bool
			{
				return false;
			}
		};
		$logger->display();

		ob_start();
		$logger->log(LogLevel::INFO, 'SELECT * FROM users WHERE name = \'<script>alert("xss")</script>\'');
		$output = (string) ob_get_clean();

		$this->assertStringContainsString('<br />', $output);
		$this->assertStringNotContainsString('<script>', $output);
		$this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $output);
	}

	public function testOutputsRawSqlWithoutHtmlBreaksInCliSapi(): void
	{
		$logger = new class () extends Logger {
			protected function isCli(): bool
			{
				return true;
			}
		};
		$logger->display();

		ob_start();
		$logger->log(LogLevel::INFO, 'SELECT * FROM users WHERE age < 30 AND score > 10');
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString('<br />', $output);
		$this->assertStringContainsString('age < 30 AND score > 10', $output);
	}

	public function testCapsMessagesAndClearMessagesResetsCollectedMessages(): void
	{
		$logger = new Logger(maxMessages: 3);
		$logger->display();

		ob_start();
		for ($i = 1; $i <= 5; $i++) {
			$logger->log(LogLevel::INFO, "SELECT {$i}");
		}
		ob_end_clean();

		$collected = $logger->collect();
		$this->assertSame(3, $collected['count']);
		$this->assertSame(['SELECT 3', 'SELECT 4', 'SELECT 5'], $collected['messages']);

		$logger->clearMessages();

		$this->assertSame([
			'count' => 0,
			'messages' => [],
		], $logger->collect());
	}
}
