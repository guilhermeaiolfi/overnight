<?php
namespace ON;

use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Request;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequestFactory;

class Page implements IPage {
  use \ON\common\AttributeHolder;
  use \ON\view\View;

  protected $application = null;

  public function __construct (Application $app) {
    $this->application = $app;
  }
  public function isSecure() {
    return false;
  }
};
?>