<?php

declare(strict_types=1);

namespace ON\Image;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;

/*
* e.g. 'resize:500,100/ar/up'
*/
class CustomTemplate implements ModifierInterface
{
	protected $contrainsMatrix = [
		"up" => "upsize",
		"ar" => "aspectRatio", // keep the aspect ratio, resizes proportionally
	];

	protected $modifiersArgsSignature = [
		"cover" => [
			"width",
			"height",
			"position",
		],
		"resize" => [
			"width",
			"height",
		],
	];

	protected $options = null;

	public function __construct($options)
	{
		$this->options = $options;
	}

	public function parseArgs($args, $callback)
	{
		$has_constraints = false;
		for ($i = 0; $i < count($args); $i++) {

			if (is_numeric($args[$i])) {
				$args[$i] = (int) $args[$i];
			} elseif ($args[$i] == '$constraints') {
				$args[$i] = $callback;
				$has_constraints = true;
			} elseif ($args[$i] == "null") {
				$args[$i] = null;
			}
		}
		if (! $has_constraints) {
			$args[] = $callback;
		}

		return $args;
	}

	public function apply(ImageInterface $image): ImageInterface
	{
		$contrainsMatrix = $this->contrainsMatrix;
		$options = $this->options;
		$commands = explode("|", $options);
		$args = [];
		foreach ($commands as $command) {
			if (! $command) {
				break;
			}
			list($method, $args) = explode(":", $command);
			$constraints = explode("/", $args);
			$args = array_shift($constraints);
			$args = explode(",", $args);

			$func = null;
			if (count($constraints)) {
				$func = function ($image) use ($constraints, $contrainsMatrix) {
					foreach ($constraints as $c) {
						$image->{$contrainsMatrix[$c]}();
					}
				};
			}
			$args = $this->parseArgs($args, $func);

			$args = $this->convertArgsToNamedArgs($method, $args);
			call_user_func_array([&$image, $method], $args);
			//dump($method, $args);
			//$image->cover(width: 200);
		}

		return $image;
	}

	public function convertArgsToNamedArgs(string $method, array $args): array
	{
		if (! isset($this->modifiersArgsSignature[$method])) {
			return $args;
		}
		$namedArgs = [];
		foreach ($this->modifiersArgsSignature[$method] as $index => $name) {
			if (isset($args[$index])) {
				$namedArgs[$name] = $args[$index];
			}
		}

		return $namedArgs;
	}
}
