<?php
namespace ON\View\Latte;

class ConfigProvider
{
    /**
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencies(),
            'paths' => [
                "latte" => 'data/cache/'
            ],
            "latte" => [
                "autorefresh" => true,
                "extension" => "latte"
            ]
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
                LatteRenderer::class => LatteRendererFactory::class
            ]
        ];
     }
}