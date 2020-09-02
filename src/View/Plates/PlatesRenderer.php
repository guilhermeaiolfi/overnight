<?php
namespace ON\View\Plates;

use League\Plates\Engine;
use Mezzio\Plates\PlatesRenderer as MezzioPlatesRenderer;

use Zend\Diactoros\Response;
use Zend\Diactoros\Request;
use Zend\Diactoros\Response\HtmlResponse;
use ON\Container\MiddlewareFactory;
use ON\Action;
use ON\View\RendererInterface;
use ON\Application;

class PlatesRenderer  implements RendererInterface
{
    protected $config = null;
    protected $engine = null;
    protected $app = null;
    public function __construct($config, Engine $engine, Application $app) {
        $this->config = $config;
        $this->engine = $engine;
        $this->app = $app;
    }

    public function render ($layout, $template_name = null, $data = null, $params = []) {

        $config = $this->config;

        //$renderer = $this->renderer;
        $engine = $this->engine;
        $app = $this->app;

        $sections = array();
        $template = $engine->make($template_name);
        if (isset($layout["sections"])) {
            foreach($layout["sections"] as $section_name => $section_config) {
                if (is_array($section_config)) {
                    $response = $this->runSection(...$section_config);

                    // create section
                    $template->start($section_name);
                        echo $response->getBody();
                    $template->end();
                }
                else {
                    // create section
                    $template->start($section_name);
                        include $section_config;
                    $template->end();
                }
            }
        }
        $template->layout($layout["name"], $data);
        return $template->render($data);
    }

    /*
        $section_config example: ["/layout/front/footer", "Core\Page\FooterPage::index", ["GET"], "layout.front.footer"]
        */
    public function runSection ($section_path, $controller, $methods, $route_name) {
        $request = $this->app->prepareRequest($section_path, $controller, $methods, $route_name);
        return $this->app->handle($request);
    }
}