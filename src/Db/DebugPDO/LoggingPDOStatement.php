<?php

declare(strict_types=1);

namespace ON\DB\DebugPDO;

use PDO;
use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PDOStatement subclass that logs query execution to the event system.
 *
 * Set as the statement class on a PDO connection via:
 *   $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingPDOStatement::class, []]);
 *
 * Then set the dispatcher:
 *   LoggingPDOStatement::setDispatcher($eventDispatcher);
 *
 * All prepare()+execute() calls will be logged. No PDO wrapper needed.
 */
class LoggingPDOStatement extends PDOStatement
{
	protected static ?EventDispatcherInterface $dispatcher = null;

	protected array $boundParameters = [];

	protected function __construct()
	{
	}

	public static function setDispatcher(?EventDispatcherInterface $dispatcher): void
	{
		static::$dispatcher = $dispatcher;
	}

	public static function getDispatcher(): ?EventDispatcherInterface
	{
		return static::$dispatcher;
	}

	public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null): bool
	{
		$this->boundParameters[$column] = $param;
		$args = array_merge([$column, &$param], array_slice(func_get_args(), 2));

		return parent::bindColumn(...$args);
	}

	public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null): bool
	{
		$this->boundParameters[$parameter] = $variable;
		$args = array_merge([$parameter, &$variable], array_slice(func_get_args(), 2));

		return parent::bindParam(...$args);
	}

	public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
	{
		$this->boundParameters[$parameter] = $value;

		return parent::bindValue(...func_get_args());
	}

	public function execute($input_parameters = null): bool
	{
		$boundParameters = $this->boundParameters;
		if (is_array($input_parameters)) {
			$boundParameters = array_merge($boundParameters, $input_parameters);
		}

		$trace = new DebugStatement($this->queryString, $boundParameters);
		$trace->start();

		$ex = null;

		try {
			$result = parent::execute($input_parameters);
		} catch (PDOException $e) {
			$ex = $e;
		}

		$trace->end($ex, $ex === null ? $this->rowCount() : 0);

		if (static::$dispatcher !== null) {
			$event = new QueryEvent('pdo.query', $trace, 'execute');
			static::$dispatcher->dispatch($event);
		}

		if ($ex !== null) {
			throw $ex;
		}

		return $result;
	}
}
