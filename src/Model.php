<?php
namespace ON;

abstract class Model implements IModel
{
  protected $application = null;
  public function __construct(Application $app) {
    $this->application = $app;
  }
}