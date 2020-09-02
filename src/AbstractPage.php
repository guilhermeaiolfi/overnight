<?php
namespace ON;

use Psr\Container\ContainerInterface;
use Zend\Diactoros\ServerRequestFactory;
use Mezzio\Router\RouteResult;
use Mezzio\Router\Route;
use Zend\Diactoros\Response;
use Zend\Diactoros\Request;
use Zend\Diactoros\Response\HtmlResponse;
use ON\Application;
use ON\Common\AttributesTrait;
use ON\Container\MiddlewareFactory;
use ON\Action;

abstract class AbstractPage implements IPage {
  use AttributesTrait;

  protected $container = null;

  protected $default_template_name;

  public function __construct (ContainerInterface $c) {
    $this->container = $c;
  }

  public function isSecure () {
    return false;
  }

  /*
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
  }*/

  public function defaultIndex () {
    return 'Success';
  }

  public function defaultHandleError () {
    return 'Error';
  }

  public function defaultValidate () {
    return true;
  }

  public function defaultCheckPermissions () {
    return true;
  }

  public function defaultIsSecure () {
    return false;
  }

  public function getDefaultTemplateName () {
    return $this->default_template_name;
  }

  public function setDefaultTemplateName ($template_name) {
    $this->default_template_name = $template_name;
  }

  public function render($layout_name = null, $template_name = null, $data = null, $params = []) {

    $container = $this->container;
    $config = $this->container->get('config');

    // if nothing is passed, use the attributes of the page instance
    if (!isset($data)) {
      $data = $this->getAttributes();
    }

    // get the page method executed to determine the template name
    if (!isset($template_name)) {
      $template_name = $this->getDefaultTemplateName();
      if (!isset($template_name)) {
        throw new \Exception("No template name set.");
      }
    }

    if (!isset($layout_name)) {
      $layout_name = isset($config["output_types"]["html"]["default"])? $config["output_types"]["html"]["default"] : 'default';
    }
    if (!isset($config['output_types']['html']['layouts'][$layout_name])) {
      throw new \Exception("There is no configuration for layout name: \"" . $layout_name . " \"");
    }
    $layout_config = $config['output_types']['html']['layouts'][$layout_name];

    $renderer_name = isset($params['renderer'])? $params['renderer'] : $layout_config['renderer'];
    $renderer_config = $config['output_types']['html']['renderers'][$renderer_name];

    $renderer_class = isset($renderer_config['class'])? $renderer_config['class'] : '\ON\Renderer';


    $renderer = $this->container->get($renderer_class);

    if ($assigns = $renderer_config['inject']) {
      foreach($assigns as $key => $class) {
        $data[$key] = $this->container->get($class);
      }
    }

    // get the page method executed to determine the template name
    if (!isset($template_name)) {
        throw new \Exception("No template name set.");
    }

    $layout_config["name"] = $layout_name;
    return $renderer->render($layout_config, $template_name, $data);
  }

  public function processForward($middleware, $request) {
    $result = $request->getAttribute(RouteResult::class);
    $matched = $result->getMatchedRoute();
    $result = RouteResult::fromRoute(new Route($matched->getPath(), $middleware, $matched->getAllowedMethods(), $matched->getName()));
    $request = $request->withAttribute(RouteResult::class, $result);
    return $this->container->get(Application::class)->runAction($request);
  }

  public function getContainer() {
    return $this->container;
  }
}