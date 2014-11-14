<?php
include __DIR__ . "/../vendor/autoload.php";

class ViewTest extends PHPUnit_Framework_TestCase
{
  public function testSomething()
  {
    $config = ["output_types" =>
      [
        "html" => [
          "renderers" => [
            "php" => [
              "class" => "\ON\Renderer",
              "assigns" => [
                "router" => "ro"
              ]
            ]
          ],
          "layouts" => [
            "default" => [
              "renderer" => 'php',
              "file" => "layouts/default.php"
            ]
          ],

        ]
      ]
    ];
    $app = new \ON\Application($config);

    $page = new \ON\Page($app);
    $view = $page->setupView('default');
    $this->assertEquals($view->getAssign('ro'), $app->container->router);
  }
}

?>