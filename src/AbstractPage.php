<?php
namespace ON;

use Psr\Container\ContainerInterface;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\Route;
use Zend\Diactoros\Response;
use Zend\Diactoros\Request;
use Zend\Diactoros\Response\HtmlResponse;
use League\Plates\Engine;
use ON\Application;
use ON\Common\AttributesTrait;

abstract class AbstractPage implements IPage {
  use AttributesTrait;

  protected $container = null;

  public function __construct (ContainerInterface $c) {
    $this->container = $c;
  }

  public function isSecure() {
    return false;
  }

  public function checkPermissions () {
    return true;
  }

  public function index () {
    return 'Success';
  }

  public function handleError () {
    return 'Error';
  }

  public function validate () {
    return true;
  }

  public function render($layout_name, $template_name, $data = null, $params = []) {
    // if nothing is passed, use the attributes of the page instance
    if (!isset($data)) {
      $data = $this->getAttributes();
    }

    $container = $this->container;
    $config = $this->container->get('config');
    $layout_config = $config['output_types']['html']['layouts'][$layout_name];

    $renderer_name = isset($params['renderer'])? $params['renderer'] : $layout_config['renderer'];
    $renderer_config = $config['output_types']['html']['renderers'][$renderer_name];

    $renderer_class = isset($renderer_config['class'])? $renderer_config['class'] : '\ON\Renderer';

    $engine = $this->container->get(Engine::class);

    // Set file extension
    if (isset($config['templates']['extension'])) {
        $engine->setFileExtension($config['templates']['extension']);
    }

    $renderer = new $renderer_class($engine);

    $allPaths = isset($config['templates']['paths']) && is_array($config['templates']['paths']) ? $config['templates']['paths'] : [];
    foreach ($allPaths as $namespace => $paths) {
      $namespace = is_numeric($namespace) ? null : $namespace;
      foreach ((array) $paths as $path) {
        $renderer->addPath($path, $namespace);
      }
    }

    if ($assigns = $renderer_config['inject']) {
      foreach($assigns as $key => $class) {
        $data[$key] = $this->container->get($class);
      }
    }

    $sections = array();
    $template = $engine->make($template_name);
    if (isset($layout_config["sections"])) {
      foreach($layout_config["sections"] as $section_name => $section_config) {
        if (is_array($section_config)) {
          $request = ServerRequestFactory::fromGlobals();
          $route = new Route(...$section_config);
          $route_result = RouteResult::fromRoute($route);

          $request = $request->withAttribute(RouteResult::class, $route_result);

          $request = $request->withAttribute("PARENT-REQUEST", $request);

          if (!isset($section_config["renderer"])) {
            $section_config["renderer"] = $renderer_name;
          }
          $response = new Response();
          $app = $this->container->get(Application::class);

          $response = $app->runAction($request, $response);
          // create section
          $template->start($section_name);
            echo $response->getBody();
          $template->end();
        }
        else {
          // create section
          $template->start($section_name);
          include $section_config;
          $template->end();
        }
      }
    }

    $template->layout($layout_name, $data);

    return $template->render($data);
  }

};
?>