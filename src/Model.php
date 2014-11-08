<?php
namespace ON;

abstract class Model implements IModel
{
  protected $container = null;
  protected $application = null;
  public function __construct(Application $app, Container $container) {
    $this->application = $app;
    $this->container = $container;
  }
}