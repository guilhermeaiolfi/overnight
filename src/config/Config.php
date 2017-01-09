<?php
namespace ON\config;

class Config {

  protected $data = null;

  public function __construct($config) {
    $this->data = $config;
  }
  /*public function loadConfigDefaults () {
    $this->config = require_once(__DIR__ . "/defaults/all.php");
  }*/

  /*public function loadConfigs($config) {
    if (is_array($config)) {
      $this->config = $config;
    } else {
      $this->loadConfigFiles($config);
    }
  }*/

 /* public function loadConfigFiles($config_path) {
    $this->loadConfigDefaults();
    $files = glob($config_path . '*.php', GLOB_BRACE);
    $ignore_config = array();
    foreach($files as $file) {
      $content = require_once($file);
      $name = basename($file, ".php");
      if ($content && !in_array($name, $ignore_config)) {
        if (is_array($content) && isset($this->config[$name]) && is_array($this->config[$name])) {
          $this->config[$name] = array_replace_recursive($this->config[$name], $content);
        } else {
          $this->config[$name] = $content;
        }
      }
    }
  }*/

  public function get($path, $default = null) {
    $current = $this->data;
    $p = strtok($path, '.');

    while ($p !== false) {
      if (!isset($current[$p])) {
        return $default;
      }
      $current = $current[$p];
      $p = strtok('.');
    }
    return $current;
  }

  public function set($path, $value) {
    $current = $this->data;
    $p = strtok($path, '.');

    while ($p !== false) {
      if (!isset($current[$p])) {
        $current[$p] = array();
      }
      $current[$p] = $current;
      $p = strtok('.');
    }
    $current = $value;
  }
};

?>