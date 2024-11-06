<?php

declare(strict_types=1);

use ON\Application;
use ON\Page;

include __DIR__ . "/../vendor/autoload.php";

/**
 * @internal
 */
class RendererTest extends PHPUnit_Framework_TestCase
{
	public function test_something()
	{
		$app = new Application("");

		$page = new Page($app);
		$page->setAttribute('before', 'ON');
		$view = $page->setupView('default');
		$page->setAttribute('after', 'ON');
		$this->assertEquals($view->getAttribute('before'), 'ON');
		$this->assertEquals($page->getAttribute('before'), 'ON');
		$this->assertEquals($page->getAttribute('after'), 'ON');
		$this->assertEquals($view->getAttribute('after'), 'ON');
	}
}
