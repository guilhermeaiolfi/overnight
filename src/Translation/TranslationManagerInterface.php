<?php
namespace ON\Translation;

/**
  * HEAVY INSPIRED BY AgaviTrasnlationManager
**/
interface TranslationManagerInterface
{

  public function __construct(array $config = array());

  public function getAvailableLocales();

  public function setLocale($identifier);

  public function getCurrentLocale();

  public function getCurrentLocaleIdentifier();

  public function getDefaultLocale();

  public function getDefaultLocaleIdentifier();

  public function setDefaultDomain($domain);

  public function getDefaultDomain();

  public function _d($date, $domain = null, $locale = null);

  public function _c($number, $domain = null, $locale = null);

  public function _n($number, $domain = null, $locale = null);

  public function _($message, $domain = null, $locale = null, array $parameters = null);

  public function __($singularMessage, $pluralMessage, $amount, $domain = null, $locale = null, array $parameters = null);


  public function getDomainTranslator($domain, $type);

  public function getLocaleIdentifier($identifier);

  public function getLocale($identifier, $forceNew = false);

  public function setDefaultTimeZone($id);

  public function getCurrentTimeZone();

  public function getDefaultTimeZone();
}

?>