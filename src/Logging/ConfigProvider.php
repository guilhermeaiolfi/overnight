<?php
namespace ON\Logging;

use ON\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

class ConfigProvider
{
    /**
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencies()
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
                LoggerInterface::class => LoggerFactory::class
            ]
        ];
     }
}