<?php
namespace ON\View\Plates;

use League\Plates\Engine;

class ConfigProvider
{
    /**
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /**
     * @return array
     */
    public function getDependencies()
    {
         return [
            'aliases' =>[


            ],
            'factories' => [
                Engine::class         => PlatesEngineFactory::class,
                PlatesRenderer::class => PlatesRendererFactory::class
            ]
        ];
     }
}