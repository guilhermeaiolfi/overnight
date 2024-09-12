<?php

namespace ON\Db\DebugPDO;

use PDO;
use PDOException;

class DebugPDOStatement extends \PDOStatement {
    protected array $boundParameters = [];

    protected function __construct (protected DebugPDO $pdo)    {
    }
    
    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null): bool
    {
        $this->boundParameters[$column] = $param;
        $args = array_merge([$column, &$param], array_slice(func_get_args(), 2));
        return parent::bindColumn(...$args);
    }

    public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null) : bool
    {
        $this->boundParameters[$parameter] = $variable;
        $args = array_merge([$parameter, &$variable], array_slice(func_get_args(), 2));
        return parent::bindParam(...$args);
    }

    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR) : bool
    {
        $this->boundParameters[$parameter] = $value;
        return parent::bindValue(...func_get_args());
    }
          
     public function execute($input_parameters = null) : bool
    {
        $preparedId = spl_object_hash($this);
        $boundParameters = $this->boundParameters;
        if (is_array($input_parameters)) {
            $boundParameters = array_merge($boundParameters, $input_parameters);
        }

        $trace = new DebugStatement($this->queryString, $boundParameters, $preparedId);
        $trace->start();

        $ex = null;
        try {
            $result = parent::execute($input_parameters);
        } catch (PDOException $e) {
            $ex = $e;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION && $result === false) {
            $error = $this->errorInfo();
            $ex = new PDOException($error[2], (int) $error[0]);
        }

        $trace->end($ex, $this->rowCount());
        $this->pdo->emitEvent($trace, 'execute');

        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION && $ex !== null) {
            throw $ex;
        }
        return $result;
    }
}