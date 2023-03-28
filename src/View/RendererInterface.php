<?php
namespace ON\View;

interface RendererInterface {

    public function render ($layout, $template_name, $data, $params);
}
