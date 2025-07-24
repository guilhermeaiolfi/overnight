<?php

declare(strict_types=1);

namespace ON;

use Exception;
use ON\Common\AttributesTrait;
use ON\View\ViewConfig;

abstract class AbstractPage implements PageInterface
{
	use AttributesTrait;

	protected $default_template_name;

	public function isSecure()
	{
		return false;
	}

	/*
	public function checkPermissions () {
	  return true;
	}

	public function index () {
	  return 'Success';
	}

	public function handleError () {
	  return 'Error';
	}

	public function validate () {
	  return true;
	}*/

	public function defaultIndex()
	{
		return 'Success';
	}

	public function defaultHandleError()
	{
		return 'Error';
	}

	public function defaultValidate()
	{
		return true;
	}

	public function defaultCheckPermissions()
	{
		return true;
	}

	public function defaultIsSecure()
	{
		return false;
	}

	public function getDefaultTemplateName()
	{
		return $this->default_template_name;
	}

	public function setDefaultTemplateName($template_name)
	{
		$this->default_template_name = $template_name;
	}

	private function getApplicationInstance(): Application
	{
		return Application::$instance;
		;
	}

	public function render($layout_name = null, $template_name = null, $data = null, $params = [])
	{
		$app = $this->getApplicationInstance();

		if (! $app->hasExtension('view')) {
			throw new Exception("You are trying to render something but has not installed any view extension.", 1);

			return;

		}
		$config = $app->container->get(ViewConfig::class);

		// if nothing is passed, use the attributes of the page instance
		if (! isset($data)) {
			$data = $this->getAttributes();
		}

		// get the page method executed to determine the template name
		if (! isset($template_name)) {
			$template_name = $this->getDefaultTemplateName();
			if (! isset($template_name)) {
				throw new Exception("No default template name set.");
			}
		}

		if (! isset($layout_name)) {
			$layout_name = $config["formats"]["html"]["default"] ?? 'default';
		}
		if (! isset($config["formats"]['html']['layouts'][$layout_name])) {
			throw new Exception("There is no configuration for layout name: \"" . $layout_name . " \"");
		}
		$layout_config = $config["formats"]['html']['layouts'][$layout_name];

		$renderer_name = $params['renderer'] ?? $layout_config['renderer'];
		$renderer_config = $config["formats"]['html']['renderers'][$renderer_name];

		$renderer_class = $renderer_config['class'] ?? '\ON\Renderer';

		$renderer = $app->container->get($renderer_class);

		if ($assigns = $renderer_config['inject']) {
			foreach ($assigns as $key => $class) {
				$data[$key] = $app->container->get($class);
			}
		}
		// get the page method executed to determine the template name
		if (! isset($template_name)) {
			throw new Exception("It was impossible to figure it out the layout to use to render this page.");
		}
		$layout_config["name"] = $layout_name;

		return $renderer->render($layout_config, $template_name, $data);
	}

	public function processForward($middleware, $request)
	{
		$app = $this->getApplicationInstance();
		$app->processForward($middleware, $request);
	}

	public function getContainer()
	{
		$app = $this->getApplicationInstance();

		return $app->container;
	}
}
