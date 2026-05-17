<?php

declare(strict_types=1);

namespace ON\DB\DebugPDO;

use ON\Clockwork\ClockworkQueryRecorder;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDOStatement subclass that records prepared-statement queries for Clockwork.
 *
 * Set as the statement class on a PDO connection via:
 *   $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingPDOStatement::class, []]);
 */
class LoggingPDOStatement extends PDOStatement
{
	protected array $boundParameters = [];

	protected function __construct()
	{
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

		ClockworkQueryRecorder::record(
			$trace->getSql(),
			$trace->getParameters(),
			$trace->getDuration()
		);

		if ($ex !== null) {
			throw $ex;
		}

		return $result;
	}
}
