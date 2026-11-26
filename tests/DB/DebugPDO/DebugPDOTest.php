<?php

declare(strict_types=1);

namespace Tests\ON\DB\DebugPDO;

use ON\DB\DebugPDO\DebugPDO;
use PDO;
use PHPUnit\Framework\TestCase;

final class DebugPDOTest extends TestCase
{
	public function testAlphanumericSqlstateDoesNotThrowTypeErrorInSilentMode(): void
	{
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

		$debugPdo = new class ($pdo) extends DebugPDO {
			public mixed $capturedTrace = null;

			public function recordQuery($trace): void
			{
				$this->capturedTrace = $trace;
			}
		};

		$result = $debugPdo->exec('SELECT * FROM table_that_does_not_exist');

		$this->assertFalse($result);
		$this->assertSame('HY000', $debugPdo->errorCode());
		$this->assertNotNull($debugPdo->capturedTrace);
		$this->assertFalse($debugPdo->capturedTrace->isSuccess());
		$this->assertNotSame('', $debugPdo->capturedTrace->getErrorMessage());
	}
}
