<?php
namespace ON\View\Plates;

use League\Plates\Engine;

use ON\View\RendererInterface;
use ON\Application;
use ON\View\ViewConfig;
use ON\Extension\PipelineExtension;

class PlatesRenderer  implements RendererInterface
{
    public function __construct(
        protected ViewConfig $config, 
        protected Engine $engine, 
        protected Application $app) 
    {
    }

    public function render ($layout, $template_name = null, $data = null, $params = []) {
        $engine = $this->engine;

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
        $request = $this->app->getExtension(PipelineExtension::class)->prepareRequest($section_path, $controller, $methods, $route_name);
        return $this->app->handle($request);
    }
}