<?php namespace ON\Clockwork\DataSource;

use Clockwork\DataSource\DataSource;
use Clockwork\Request\Log;
use Clockwork\Request\Request;
use ON\Clockwork\Handler\MonologHandler;
use Psr\Log\LoggerInterface;
use Monolog\Logger;

// Data source for Monolog, provides application log
class PsrLoggerDatasource extends DataSource
{
	// Clockwork log instance
	protected $log;

	// Create a new data source, takes Monolog instance as an argument
	public function __construct(Logger $logger)
	{
		$this->log = new Log;

		$logger->pushHandler(new MonologHandler($this->log));
	}

	// Adds log entries to the request
	public function resolve(Request $request)
	{
		$request->log()->merge($this->log);

		return $request;
	}

	// Reset the data source to an empty state, clearing any collected data
	public function reset()
	{
		$this->log = new Log;
	}
}
