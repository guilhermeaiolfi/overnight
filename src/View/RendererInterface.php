<?php

declare(strict_types=1);

namespace ON\View;

interface RendererInterface
{
	public function render($layout, $template_name, $data, $params);
}
