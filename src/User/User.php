<?php

namespace ON\User;

class StandardUser implements UserInterface {
  protected $data;
  public function __construct ($data) {
    $this->data = isset($data)? $data : [];
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
}