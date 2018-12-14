<?php
declare (strict_types=1);

namespace ON\PhpDebugBar;

use DebugBar\DebugBar;
use DebugBar\JavascriptRenderer;
use Psr\Container\ContainerInterface;
use PhpMiddleware\PhpDebugBar\ConfigProvider;

final class JavascriptRendererFactory
{
    public function __invoke(ContainerInterface $container): JavascriptRenderer
    {
        $debugbar = $container->get(DebugBar::class);
        $config = $container->get('config');
        $rendererOptions = $config['phpmiddleware']['phpdebugbar']['javascript_renderer'];

        $renderer = new JavascriptRenderer($debugbar);
        $renderer->setOptions($rendererOptions);

        return $renderer;
    }
}
