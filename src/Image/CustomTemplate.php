<?php

namespace ON\Image;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ModifierInterface;

/*
* e.g. 'resize:500,100/ar/up'
*/
class CustomTemplate implements ModifierInterface
{
    protected $matrix = [
            "up" => "upsize",
            "ar" => "aspectRatio"
    ];

    protected $options = null;

    public function __construct($options) {
        $this->options = $options;
    }
    public function parseArgs ($args, $callback) {
        $has_constraints = false;
        for ($i = 0; $i < count($args); $i++) {
            $args[$i] = is_numeric($args[$i])? (int) $args[$i] : $args[$i];
            if ($args[$i] == '$constraints') {
                $args[$i] = $callback;
                $has_constraints = true;
            }
        }
        if (!$has_constraints) {
            $args[] = $callback;
        }
        return $args;
    }
    public function apply(ImageInterface $image): ImageInterface
    {
        $matrix = $this->matrix;
        $options = $this->options;
        $commands = explode("|", $options);
        $args = [];
        foreach ($commands as $command) {
            if (!$command) break;
            list ($method, $args) = explode(":", $command);
            $constraints = explode("/", $args);
            $args = array_shift($constraints);
            $args = explode(",", $args);

            $func = null;
            if (count($constraints)) {
                $func = function ($image) use ($constraints, $matrix) {
                    foreach ($constraints as $c) {
                        $image->{$matrix[$c]}();
                    }
                };
            }
            $args = $this->parseArgs($args, $func);
            call_user_func_array([&$image, $method], $args);
        }

        return $image;
    }
}
