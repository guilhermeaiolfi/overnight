<?php
namespace ON\Common;

trait AttributesTrait {
  protected $attributes = array();

  public function setAttributes($attributes) {
    $this->attributes = $attributes;
  }

  public function setAttributesByRef(array &$attributes)
  {
    if(!isset($this->attributes)) {
        $this->attributes = array();
    }

    foreach($attributes as $key => &$value) {
        $this->attributes[$key] =& $value;
    }
  }

  public function clearAttributes()
  {
    $this->attributes = array();
  }

  public function setAttributeByRef($name, &$value)
  {
    if(!isset($this->attributes)) {
        $this->attributes[$ns] = array();
    }

    $this->attributes[$name] =& $value;
  }

  public function setAttribute($name, $value) {
    $this->attributes[$name] = $value;
  }

  public function &getAttributes () {
    return $this->attributes;
  }

  public function hasAttribute($name) {
    return isset($this->attributes[$name]);
  }

  public function getAttribute($name, $default = null) {
    return !$this->hasAttribute($name)? $default : $this->attributes[$name];
  }
}
?>