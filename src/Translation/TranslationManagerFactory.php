<?php

declare(strict_types=1);

namespace ON\Translation;

use Psr\Container\ContainerInterface;

class TranslationManagerFactory
{
	public function __invoke(ContainerInterface $container): TranslationManagerInterface
	{
		$cfg = $container->get(TranslationConfig::class);

		return new TranslationManager(
			$cfg->get()
		);
	}
}
