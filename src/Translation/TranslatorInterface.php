<?php
namespace ON\Translation;

use ON\Translation\TranslationManagerInterface;

interface TranslatorInterface
{
  public function __construct(TranslationManagerInterface $tm, array $parameters = array());

  public function translate($message, $domain, $locale = null);
}