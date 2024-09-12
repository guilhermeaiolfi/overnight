<?php
namespace ON;

use Psr\Container\ContainerInterface;

class Data {
	protected $container;
	public function __construct (ContainerInterface $c) {
		$this->container = $c;
	}

	protected function resolveCallback ($model) {
		$model = explode("/", $model);
		$count = count($model);
		$method = $model[$count - 1];
		unset($model[$count - 1]);

		$className = implode("\\", $model);
		if (class_exists($className)) {
			$model = $className;
		} else if ($count == 3) {
			$model = $model[0] . "\\Model\\" . $model[1] . "Model";
		} else {
			$model = $model[0] . "\\Model\\" . $model[0] . "Model";
		}
		$model = $this->container->get($model);
		return [$model, $method];
	}
	/*
	* ->execute('Core/Backup/backup', []); => Core\Model\BackupModel
	*/
	public function execute($model, $data = []) {
		$callback = $this->resolveCallback($model);
		return call_user_func_array($callback, $data);
	}

	protected function isCommand ($key) {
		return strpos($key, ":") !== FALSE;
	}

	protected function getArgumentsData($command, $variables) {
		preg_match("/^(.+?)\((.*?)\)$/", $command, $match);
		$function_name = $match[1]; // method
		$args = explode(',', $match[2]); // arguments
		$data = [];

		//print_r($args);exit;
		foreach ($args as $arg) {
			if (strpos($arg, "{") !== FALSE) {
				$name = rtrim(ltrim($arg, '{'), '}');
				$data[] = $variables[$name];
			} else if (strpos($arg, ".") !== FALSE) {

				$dots = explode(".", $arg);
				$value = $variables;
				foreach ($dots as $dot) {
					$value = $value[$dot];
				}
				$data[] = $value;
			} else {
				$data[] = $arg;
			}
		}
		return $data;
	}

	protected function isCollection($command) {
		return substr($command,0,1) == "[" && substr($command,strlen($command)-1,1) == "]";
	}

	/*$example = [ "guilherme:User/getUserById(5)" => [
		"id",
		"name",
		"roles:User/Acl/getRoles(guilherme.id)" => [
			"id",
			"name"
		]
	];*/
 	public function query ($query, $variables = []) {
 		$result = [];
 		$values = null;
 		$pointer = &$result;
		foreach ($query as $key => $fields) {
			if ($this->isCommand($key)) {
				list($field_key, $command) = explode(":", $key);
				$is_collection = false;
				if ($is_collection = $this->isCollection($command)) {
					$command = substr(substr($command,0,-1),1);
				}
				$data = $this->getArgumentsData($command, $variables);
				$function_name = trim(explode("(", $command)[0]);
				if ($is_collection) {
					$values = $this->execute($function_name, $data);
					$result[$field_key] = [];
					foreach($values as $item) {
						if (is_array($fields)) {
							$result[$field_key][] = $this->getData($fields, [$field_key => $item], $field_key);
						} else {
							$results[$field_key][] = $values;
						}
					}
				} else if (is_array($fields)) {
					$values = $this->execute($function_name, $data);
					$result[$field_key] = $this->getData($fields, array_merge($variables, [$field_key => $values]), $field_key);
				} else {
					$values = $this->execute(trim($function_name), $data);
					$result[$field_key] = $values;
				}
				$pointer = &$result[$field_key];
			} else {
				$result[$key] = $values[$key];
			}
		}
		return $result;
	}

	protected function getData ($fields, $values, $field_key = null) {
		if (is_array($fields)) {
			$return = [];
			foreach ($fields as $key => $field) {
				if (is_numeric($key)) {
					$key = $field;
				}
				if ($this->isCommand($key)) {
					list($named_field, $command) = explode(":", $key);
					$obj = $this->query([$key => $field], $values);
					$return[$named_field] = $obj[$named_field];
				} else {
					if ($field_key) {
						$return[$field] = $values[$field_key][$field];
					} else {
						$return[$field] = $values[$field];
					}
				}
			}
			return $return;
		}
		return $values[$fields];
	}
}