<?php

declare(strict_types=1);

namespace ON\View\Plates;

use League\Plates\Engine;
use ON\Application;
use ON\View\RendererInterface;
use ON\View\ViewConfig;

class PlatesRenderer implements RendererInterface
{
	public function __construct(
		protected ViewConfig $config,
		protected Engine $engine,
		protected Application $app
	) {
	}

	public function render($layout, $template_name = null, $data = null, $params = [])
	{
		$engine = $this->engine;
		$mode = $params['mode'] ?? 'layout';

		if ($mode === 'fragment') {
			return $engine->render($template_name, $data ?? []);
		}

		$template = $engine->make('overnight::plates-rendered-content');
		$sections = $params['sections'] ?? [];

		foreach ($sections as $section_name => $section_value) {
			if ($section_name === 'content') {
				continue;
			}

			$template->start($section_name);
			echo $section_value['content'] ?? '';
			$template->end();
		}

		$data['_content'] = $sections['content']['content'] ?? '';
		$template->layout($layout["name"], $data);

		return $template->render($data);
	}
}
