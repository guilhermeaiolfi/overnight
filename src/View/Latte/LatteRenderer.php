<?php
namespace ON\View\Latte;

use Mezzio\Application;
use Mezzio\Router\RouteResult;
use Mezzio\Router\Route;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Request;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequestFactory;
use ON\Action;
use ON\View\RendererInterface;
use ON\Container\MiddlewareFactory;
use Latte\Engine;

use function explode;

class LatteRenderer  implements RendererInterface
{
    protected $config = null;
    protected $app = null;
    protected $engine = null;
    public function __construct($config, Engine $engine, Application $app) {
        $this->config = $config;
        $this->engine = $engine;
        $this->app = $app;
    }

    public function render ($layout, $template_name = null, $data = null, $params = []) {

        $config = $this->config;

        $engine = $this->engine;
        $app = $this->app;

        //print_r($layout);
        $latte_renderer_config = $config["output_types"]["html"]["renderers"]["latte"];

        $ext = isset($latte_renderer_config["extension"])? $latte_renderer_config["extension"] : $config["latte"]["extension"];

        $templatePath = $this->findTemplate($layout["name"], $ext);
        $sections = array();
        $blocks = [];
        if (isset($layout["sections"])) {
            foreach($layout["sections"] as $section_name => $section_config) {
                if (is_array($section_config)) {
                    $response = $this->runSection(...$section_config);
                    // create section
                    $blocks[$section_name] = '{block ' . $section_name .'}' . $response->getBody() . '{/block}';
                }
                else {
                    // create section
                    $blocks[$section_name] = $section_config;
                }
            }
        }
        //$engine->getCompiler()->openMacro("define", )
        $engine->onCompile[] = function ($latte) {

        };
        $contentPath = $this->findTemplate($template_name, $ext);

        $engine->addProvider('coreParentFinder', function ($template) use ($templatePath) {
            if (!$template->getReferenceType()) {
                return $templatePath;
            }
        });
        //$data["__sections"]["content"] = $engine->renderToString($contentPath, $data);
        //return $engine->renderToString($templatePath, $data);
        $templates = $blocks;
        $templates[$templatePath] = file_get_contents($templatePath);
        $templates[$contentPath] = $template["content"] = '{block content}' . file_get_contents($contentPath) . '{block}';

        $loader = new \Latte\Loaders\StringLoader($templates);
        $engine->setLoader($loader);
        return $engine->renderToString($contentPath, $data);
    }

    public function findTemplate($name, $ext) {
        list($namespace, $template_path) = explode("::", $name);
        $config = $this->config;
        $fs = null;
        $namespace_paths = $config["templates"]["paths"][$namespace];
        if (is_array($namespace_paths)) {
            foreach ($namespace_paths as $index => $path) {
                $fs_path = $path . "/" . $template_path . "." . $ext;
                if (file_exists($fs_path)) {
                    return $fs_path;
                }
            }
        } else if (is_string($namespace_paths)) {
            $fs_path = $namespace_paths . "/" . $template_path . "." . $ext;
            if (file_exists($fs_path)) {
                return $fs_path;
            }
        }
        throw new \Exception("The template filename(${fs_path}) doesn't exist", 1);

    }
    /*
    $section_config example: ["/layout/front/footer", "Core\Page\FooterPage::index", ["GET"], "layout.front.footer"]
    */
    public function runSection ($section_path, $controller, $methods, $route_name) {
        $request = $this->app->prepareRequest($section_path, $controller, $methods, $route_name);
        return $this->app->handle($request);
    }
}