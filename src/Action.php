<?php
namespace ON;

use Laminas\Diactoros\Response;
use \Mezzio\Application;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Action {
  protected $class_name = null;
  protected $action = null;
  protected $instance = null;

  public function __construct ($middleware) {
    list ($class_name, $action) = explode("::", $middleware);
    $this->class_name = $class_name;
    $this->action = $action;
  }

  public function getExecutable () {
    if ($this->action == null) {
      return $this->class_name;
    }
    if ($this->instance != null) {
      return [$this->instance, $this->action];
    }
    return [$this->class_name, $this->action];
  }

  public function getPageInstance () {
    return $this->instance;
  }

  public function setPageInstance ($instance) {
    $this->instance = $instance;
  }

  public function getActionName () {
    if (class_exists($this->class_name)) {
      return $this->action != null ? $this->action : 'index';
    }
    return null;
  }

  public function getClassName () {
    return $this->class_name;
  }

  public function isClass () {
    return $this->getActionName() != null;
  }

  static public function runAction(Application $app, ServerRequestInterface $request = null, ResponseInterface $response = null)
    {
        $response = $response ?: new Response();
        $request  = $request->withAttribute('originalResponse', $response);
        return $app->handle($request);
    }
}
?>