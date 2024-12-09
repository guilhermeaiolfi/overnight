<?php

declare(strict_types=1);

namespace ON\DB;

use Exception;
use PDO;

class PdoDatabase implements DatabaseInterface
{
	protected $resource;
	protected $connection;

	public function __construct(
		protected string $name, 
		protected DatabaseConfig $config
	)
	{
		$parameters = $config->get("databases.{$name}");

		$dsn = ! empty($parameters["dsn"]) ? $parameters["dsn"] : null;
		$username = ! empty($parameters["username"]) ? $parameters["username"] : null;
		$password = ! empty($parameters["password"]) ? $parameters["password"] : null;
		$options = ! empty($parameters["options"]) ? $parameters["options"] : null;

		try {
			$this->connection = $this->resource = new PDO($dsn, $username, $password, $options);

			if ($parameters["wrapper_class"] && class_exists($parameters["wrapper_class"])) {
				$this->connection = $this->resource = new $parameters["wrapper_class"]($this->connection);
			}

			// default connection attributes
			$attributes = [
				// lets generate exceptions instead of silent failures
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			];

			if (is_array($parameters['attributes'])) {
				foreach ((array)$parameters['attributes'] as $key => $value) {
					$attributes[is_string($key) && strpos($key, '::') ? constant($key) : $key] = is_string($value) && strpos($value, '::') ? constant($value) : $value;
				}
			}

			foreach ($attributes as $key => $value) {
				$this->connection->setAttribute($key, $value);
			}

			if (isset($parameters["init_queries"]) && is_array($parameters["init_queries"])) {
				foreach ((array)$parameters['init_queries'] as $query) {
					$this->connection->exec($query);
				}
			}
		} catch (Exception $e) {
			throw new Exception($e->getMessage(), 0, $e);
		}
	}

	public function getConnection(): mixed
	{
		return $this->connection;
	}

	public function getResource(): mixed
	{
		return $this->resource;
	}

	public function setConnection($connection)
	{
		$this->connection = $connection;
	}

	public function setResource($resource)
	{
		$this->resource = $resource;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}
}
