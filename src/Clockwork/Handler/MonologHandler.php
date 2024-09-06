<?php 

namespace ON\Clockwork\Handler;

use Clockwork\Request\Log as ClockworkLog;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

// Stores messages in a Clockwork log instance (compatible with Monolog 2.x)
class MonologHandler extends AbstractProcessingHandler 
{
	protected $clockworkLog;

	public function __construct(ClockworkLog $clockworkLog)
	{
		parent::__construct();

		$this->clockworkLog = $clockworkLog;
	}

	protected function write(LogRecord $record): void
	{
		$this->clockworkLog->log($record['level'], $record['message']);
	}
}
