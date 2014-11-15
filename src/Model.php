<?php
namespace ON;

abstract class Model implements IModel
{
  protected $context = null;
  protected $application = null;
  public function __construct(Application $app) {
    $this->application = $app;
    $this->context = $app->context;
  }
}