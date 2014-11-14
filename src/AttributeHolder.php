<?php
namespace ON;
trait AttributeHolder {
  protected $attributes = array();

  public function setAttributes($attributes) {
    $this->attributes = $attributes;
  }

  public function setAttributesByRef(&$attributes) {
    $this->attributes =& $attributes;
  }

  public function setAttribute($name, $value) {
    $this->attributes[$name] = $value;
  }

  public function getAttribute($name, $default = null) {
    //print_r($attributes);
    return isset($this->attributes[$name])? $this->attributes[$name] : $default;
  }

  public function &getAttributes() {
    return $this->attributes;
  }

}
?>