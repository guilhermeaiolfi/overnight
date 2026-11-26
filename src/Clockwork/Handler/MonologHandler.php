<?php

declare(strict_types=1);

namespace ON\Clockwork\Handler;

use Clockwork\Request\Log as ClockworkLog;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

// Stores messages in a Clockwork log instance (compatible with Monolog 3.x)
class MonologHandler extends AbstractProcessingHandler
{
	protected ClockworkLog $clockworkLog;

	public function __construct(ClockworkLog $clockworkLog)
	{
		parent::__construct();

		$this->clockworkLog = $clockworkLog;
	}

	public function setClockworkLog(ClockworkLog $clockworkLog): void
	{
		$this->clockworkLog = $clockworkLog;
	}

	protected function write(LogRecord $record): void
	{
		$this->clockworkLog->log(
			$record->level->toPsrLogLevel(),
			$record->message,
			$record->context
		);
	}
}
