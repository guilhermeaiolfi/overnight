<?php

declare(strict_types=1);

namespace ON\Common;

trait AttributesTrait
{
	protected $attributes = [];

	public function setAttributes($attributes)
	{
		$this->attributes = $attributes;
	}

	public function setAttributesByRef(array &$attributes)
	{
		if (! isset($this->attributes)) {
			$this->attributes = [];
		}

		foreach ($attributes as $key => &$value) {
			$this->attributes[$key] = &$value;
		}
	}

	public function clearAttributes()
	{
		$this->attributes = [];
	}

	public function setAttributeByRef($name, &$value)
	{
		if (! isset($this->attributes)) {
			$this->attributes = [];
		}

		$this->attributes[$name] = &$value;
	}

	public function setAttribute($name, $value)
	{
		$this->attributes[$name] = $value;
	}

	public function &getAttributes()
	{
		return $this->attributes;
	}

	public function hasAttribute($name)
	{
		return isset($this->attributes[$name]);
	}

	public function getAttribute($name, $default = null)
	{
		return ! $this->hasAttribute($name) ? $default : $this->attributes[$name];
	}

	public function mergeAttributes($attributes = [])
	{
		$this->attributes = array_merge($this->attributes, $attributes);
	}
}
