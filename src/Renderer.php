<?php
namespace ON;

class Renderer
{
  protected $basePath = "";
  protected $attributes = array();

  /**
   * @var        string A string with the default template file extension,
   *                    including the dot.
   */
  protected $defaultExtension = '';

  /**
   * @var        string The name of the array that contains the template vars.
   */
  protected $varName = 't';

  /**
   * @var        bool Whether or not the template vars should be extracted.
   */
  protected $extractVars = false;

  /**
   * @var        array An array of objects to be exported for use in templates.
   */
  protected $assigns = array();

  protected $slots = array();
  protected $layout = null;

  public function __construct() {

  }

  public function setAttributes($attributes) {
    $this->attributes = $attributes;
  }

  public function setAttributesByRef(&$attributes) {
    $this->attributes =& $attributes;
  }


  public function setAttribute($name, $value) {
    $this->attributes[$name] = $value;
  }

  public function setLayout($layout) {
    $this->layout = $layout;
  }
  public function setBasePath($base_path) {
    $this->basePath = $base_path;
  }

  public function getAttribute($name, $default = null) {
    //print_r($attributes);
    return isset($this->attributes[$name])? $this->attributes[$name] : $default;
  }

  /**
   * Get the template file extension
   *
   * @return     string The extension, including a leading dot.
   *
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function getDefaultExtension()
  {
    return $this->defaultExtension;
  }

  public function setSlot($name, $content) {
    $this->slots[$name] = $content;
  }

  public function getTemplateContent($template) {
    extract($this->assigns, EXTR_REFS | EXTR_PREFIX_INVALID, '_');

    if ($this->extractVars) {
      extract($this->attributes, EXTR_REFS | EXTR_PREFIX_INVALID, '_');
    }
    else {
      ${$this->varName} =& $this->attributes;
    }


    // render the view
    ob_start();

    require($this->basePath . $template);

    $content = ob_get_contents();
    ob_end_clean();

    return $content;
  }

  public function render($template) {

    extract($this->assigns, EXTR_REFS | EXTR_PREFIX_INVALID, '_');

    if ($this->extractVars) {
      extract($this->attributes, EXTR_REFS | EXTR_PREFIX_INVALID, '_');
    }
    else {
      ${$this->varName} =& $this->attributes;
    }


    // render the view
    ob_start();

    require($this->basePath . $template);

    $content = ob_get_contents();
    ob_end_clean();

    $slots = $this->slots;


    // render the layout
    if ($this->layout) {
      ob_start();

      require($this->basePath . $this->layout);

      $this->layout = ob_get_contents();
      ob_end_clean();

      return $this->layout;
    }

    return $content;
  }

}
?>