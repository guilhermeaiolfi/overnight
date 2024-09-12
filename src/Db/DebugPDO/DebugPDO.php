<?php

namespace ON\Db\DebugPDO;

use ON\Db\DebugPDO\DebugPDOStatement;
use PDO;
Use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;

class DebugPDO extends PDO {

    public ?EventDispatcherInterface $eventDispatcher = null;

    protected PDO $pdo;

    public function __construct (PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DebugPDOStatement::class, [$this]]);
    }

    public function setEventDispatcher (EventDispatcherInterface $dispatcher) {
        $this->eventDispatcher = $dispatcher;
    }

    public function beginTransaction() : bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commits a transaction
     *
     * @link   http://php.net/manual/en/pdo.commit.php
     * @return bool TRUE on success or FALSE on failure.
     */
    public function commit() : bool
    {
        return $this->pdo->commit();
    }

    public function errorCode(): ?string
    {
        return $this->pdo->errorCode();
    }

    /**
     * Fetch extended error information associated with the last operation on the database handle
     *
     * @link   http://php.net/manual/en/pdo.errorinfo.php
     * @return array PDO::errorInfo returns an array of error information
     */
    public function errorInfo() : array
    {
        return $this->pdo->errorInfo();
    }

    /**
     * Execute an SQL statement and return the number of affected rows
     *
     * @link   http://php.net/manual/en/pdo.exec.php
     * @param  string   $statement
     * @return int|bool PDO::exec returns the number of rows that were modified or deleted by the
     * SQL statement you issued. If no rows were affected, PDO::exec returns 0. This function may
     * return Boolean FALSE, but may also return a non-Boolean value which evaluates to FALSE.
     * Please read the section on Booleans for more information
     */
    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        return $this->profileCall('exec', $statement, func_get_args());
    }

    /**
     * Retrieve a database connection attribute
     *
     * @link   http://php.net/manual/en/pdo.getattribute.php
     * @param  int   $attribute One of the PDO::ATTR_* constants
     * @return mixed A successful call returns the value of the requested PDO attribute.
     * An unsuccessful call returns null.
     */
    #[\ReturnTypeWillChange]
    public function getAttribute($attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }

     /**
     * Set an attribute
     *
     * @link   http://php.net/manual/en/pdo.setattribute.php
     * @param  int $attribute
     * @param  mixed $value
     * @return bool TRUE on success or FALSE on failure.
     */
    public function setAttribute($attribute, $value): bool
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * Checks if inside a transaction
     *
     * @link   http://php.net/manual/en/pdo.intransaction.php
     * @return bool TRUE if a transaction is currently active, and FALSE if not.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Returns the ID of the last inserted row or sequence value
     *
     * @link   http://php.net/manual/en/pdo.lastinsertid.php
     * @param  string $name [optional]
     * @return string If a sequence name was not specified for the name parameter, PDO::lastInsertId
     * returns a string representing the row ID of the last row that was inserted into the database.
     */
    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Prepares a statement for execution and returns a statement object
     *
     * @link   http://php.net/manual/en/pdo.prepare.php
     * @param  string $statement This must be a valid SQL statement template for the target DB server.
     * @param  array  $driver_options [optional] This array holds one or more key=&gt;value pairs to
     * set attribute values for the PDOStatement object that this method returns.
     * @return TraceablePDOStatement|bool If the database server successfully prepares the statement,
     * PDO::prepare returns a PDOStatement object. If the database server cannot successfully prepare
     * the statement, PDO::prepare returns FALSE or emits PDOException (depending on error handling).
     */
    #[\ReturnTypeWillChange]
    public function prepare(string $statement, array $driver_options = []): PDOStatement|false
    {
        return $this->pdo->prepare($statement, $driver_options);
    }
       
    public function query($statement, $fetchMode = null, ...$fetchModeArgs)
    {
        return $this->profileCall('query', $statement, func_get_args());
    }

    protected function profileCall($method, $sql, array $args)
    {
        $trace = new DebugStatement($sql);
        $trace->start();

        $ex = null;
        try {
            $result = $this->__call($method, $args);
        } catch (PDOException $e) {
            $ex = $e;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION && $result === false) {
            $error = $this->pdo->errorInfo();
            $ex = new PDOException($error[2], $error[0]);
        }

        $trace->end($ex);

        $this->emitEvent($trace, $method);

        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION && $ex !== null) {
            throw $ex;
        }
        return $result;
    }

    public function emitEvent($trace, $type) {
        if ($this->eventDispatcher != false) {
            $event = new QueryEvent("pdo.query", $trace, $type);
            $this->eventDispatcher->dispatch($event);
        }
    }
    
    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->pdo->$name;
    }

    /**
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $this->pdo->$name = $value;
    }

    public function __call ($name, $args)    {
        return call_user_func_array (array ($this->pdo, $name), $args); 
    }
}