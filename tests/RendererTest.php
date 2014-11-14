<?php
include __DIR__ . "/../vendor/autoload.php";
// class PageIndex extends \ON\Page {
//   public function indexView() {
//     $this->setupView('default');
//   }
// }
class RendererTest extends PHPUnit_Framework_TestCase
{
  public function testSomething()
  {
    $app = new \ON\Application("");

    $page = new \ON\Page($app, $app->container);
    $page->setAttribute('before', 'ON');
    $view = $page->setupView('default');
    $page->setAttribute('after', 'ON');
    $this->assertEquals($view->getAttribute('before'), 'ON');
    $this->assertEquals($page->getAttribute('before'), 'ON');
    $this->assertEquals($page->getAttribute('after'), 'ON');
    $this->assertEquals($view->getAttribute('after'), 'ON');
  }
}