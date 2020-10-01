<?php
declare(strict_types=1);

namespace ON\Translation;

use Psr\Container\ContainerInterface;

class TranslationManagerFactory
{
    public function __invoke(ContainerInterface $container) : TranslationManagerInterface
    {
        return new TranslationManager(
            $container->get("config")["translation"]
        );
    }
}
