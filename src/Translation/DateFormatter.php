<?php
namespace ON\Translation;

use ON\Translation\TranslationManagerInterface;

class DateFormatter implements TranslatorInterface
{
  /**
   * @var        AgaviLocale An AgaviLocale instance.
   */
  protected $locale = null;

  /**
   * @var        string The type of the formatter (date|time|datetime).
   */
  protected $type = null;

  /**
   * @var        string The custom format string (if any).
   */
  protected $customFormat = null;

  /**
   * @var        string The translation domain to translate the format (if any).
   */
  protected $translationDomain = null;

  protected $translationManager = null;


  public function __construct(TranslationManagerInterface $tm, array $parameters = array())
  {
    $type = 'datetime';
    $this->translationManager = $tm;

    $this->format = $parameters["format"];

   /* if(isset($parameters['translation_domain'])) {
      $this->translationDomain = $parameters['translation_domain'];
    }
    if(isset($parameters['type']) && in_array($parameters['type'], array('date', 'time'))) {
      $type = $parameters['type'];
    }
    if(isset($parameters['format'])) {
      $this->customFormat = $parameters['format'];
      if(is_array($this->customFormat)) {
        // it's an array, so it contains the translations already, DOMAIN MUST NOT BE SET
        $this->translationDomain = null;
      }
    }
    $this->type = $type;*/
  }

  /**
   * Translates a message into the defined language.
   *
   * @param      mixed       The message to be translated.
   * @param      string      The domain of the message.
   * @param      AgaviLocale The locale to which the message should be
   *                         translated.
   *
   * @return     string The translated message.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function translate($message, $domain, $locale = null)
  {
    $date = $message;
    if (is_null($message)) {
      $message = "now";
    }
    if (is_string($message)) {
      $date = new \DateTime($message);
    }

    $date->setTimezone(new \DateTimeZone($this->translationManager->getDefaultTimeZone()));
    $str = strftime($this->format, $date->getTimestamp());
    if(!mb_check_encoding($str,'utf-8')) $str = utf8_encode($str);
    return $str;
  }
}