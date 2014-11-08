<?php
namespace ON;

interface IPage {
  public function __construct (Application $app, Container $container);
  public function setupView($layout_name, $params = null);
  public function setContainer($container);
  public function getContainer($container);
};
?>