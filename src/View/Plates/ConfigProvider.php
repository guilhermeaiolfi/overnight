<?php
namespace ON\View\Plates;

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
                PlatesRenderer::class => PlatesRendererFactory::class
            ]
        ];
     }
}