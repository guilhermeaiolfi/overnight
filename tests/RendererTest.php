<?php
include __DIR__ . "/../vendor/autoload.php";

class RendererTest extends PHPUnit_Framework_TestCase
{
  public function testSomething()
  {
    $app = new \ON\Application("");

    $page = new \ON\Page($app);
    $page->setAttribute('before', 'ON');
    $view = $page->setupView('default');
    $page->setAttribute('after', 'ON');
    $this->assertEquals($view->getAttribute('before'), 'ON');
    $this->assertEquals($page->getAttribute('before'), 'ON');
    $this->assertEquals($page->getAttribute('after'), 'ON');
    $this->assertEquals($view->getAttribute('after'), 'ON');
  }
}

?>