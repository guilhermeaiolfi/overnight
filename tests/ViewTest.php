<?php

declare(strict_types=1);

use ON\Application;
use ON\Page;

include __DIR__ . "/../vendor/autoload.php";

/**
 * @internal
 */
class ViewTest extends PHPUnit_Framework_TestCase
{
	public function test_something()
	{
		$config = ["output_types" =>
		  [
		  	"html" => [
		  		"renderers" => [
		  			"php" => [
		  				"class" => "\ON\Renderer",
		  				"assigns" => [
		  					"router" => "ro",
		  				],
		  			],
		  		],
		  		"layouts" => [
		  			"default" => [
		  				"renderer" => 'php',
		  				"file" => "layouts/default.php",
		  			],
		  		],

		  	],
		  ],
		];
		$app = new Application($config);

		$page = new Page($app);
		$view = $page->setupView('default');
		$this->assertEquals($view->getAssign('ro'), $app->context->router);
	}
}
