<?php

namespace ON\User;

class StandardUser implements UserInterface {
  protected $data;
  public function __construct ($data) {
    $this->data = isset($data)? $data : [];
  }

  public function __unset($name)
  {
    unset($this->data[$name]);
  }

  public function __set ($name, $value) {
    $this->data[$name] = $value;
  }

  public function __get ($name) {
    if (isset($this->data[$name])) {
        return $this->data[$name];
    }
    return null;
  }

  public function getRole () {
    return $this->data["role"];
  }

  public function getUsername () {
    return $this->data["username"];
  }

  public function getName () {
    return $this->data["name"];
  }

  public function getEmail () {
    return $this->data["email"];
  }

  public function getId () {
    return $this->data["id"];
  }

  public function getCurrentUser () {
    return $this->data;
  }
}