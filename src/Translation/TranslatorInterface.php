<?php

declare(strict_types=1);

namespace ON\Translation;

interface TranslatorInterface
{
	public function __construct(TranslationManagerInterface $tm, array $parameters = []);

	public function translate($message, $domain, $locale = null);
}
