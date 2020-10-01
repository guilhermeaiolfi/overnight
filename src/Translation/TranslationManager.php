<?php
namespace ON\Translation;

/**
  * HEAVY INSPIRED BY AgaviTrasnlationManager
**/
class TranslationManager implements TranslationManagerInterface
{
  const MESSAGE = 'msg';
  const NUMBER = 'num';
  const CURRENCY = 'cur';
  const DATETIME = 'date';

  /**
   * @var        array An array of the translator instances for the domains.
   */
  protected $translators = array();

  /**
   * @var        AgaviLocale The current locale.
   */
  protected $currentLocale = null;

  /**
   * @var        string The original locale identifier given to this instance.
   */
  protected $givenLocaleIdentifier = null;

  /**
   * @var        string The identifier of the current locale.
   */
  protected $currentLocaleIdentifier = null;

  /**
   * @var        string The default locale identifier.
   */
  protected $defaultLocaleIdentifier = null;

  /**
   * @var        string The default domain which shall be used for translation.
   */
  protected $defaultDomain = null;

  /**
   * @var        array The available locales which have been defined in the
   *                   translation.xml config file.
   */
  protected $availableConfigLocales = array();

  /**
   * @var        array All available locales. Just stores the info for lazyload.
   */
  protected $availableLocales = array();

  /**
   * @var        array A cache for locale instances.
   */
  protected $localeCache = array();

  /**
   * @var        array A cache for locale identifiers resolved from a string.
   */
  protected $localeIdentifierCache = array();

  /**
   * @var        array A cache for the data of the available locales.
   */
  protected $localeDataCache = array();

  /**
   * @var        array The supplemental data from the cldr
   */
  protected $supplementalData = array();

  /**
   * @var        array The list of available time zones.
   */
  protected $timeZoneList = array();

  /**
   * @var        array A cache for the time zone instances.
   */
  protected $timeZoneCache = array();

  /**
   * @var        string The default time zone. If not set the timezone php
   *                    will be used as default.
   */
  protected $defaultTimeZone = null;

  public function parseLocaleIdentifier($identifier)
  {
    // the only important thing here is the forward assertion which is needed
    // so it doesn't match the first character of the territory
    $baseLocaleRx = '(?P<language>[^_@]{2,3})(?:_(?P<script>[^_@](?=@|_|$)|[^_@]{4,}))?(?:_(?P<territory>[^_@]{2,3}))?(?:_(?P<variant>[^@]+))?';
    $optionsRx = '@(?P<options>.*)';

    $localeRx = '#^(' . $baseLocaleRx . ')(' . $optionsRx . ')?$#';

    $localeData = array(
      'language' => null,
      'script' => null,
      'territory' => null,
      'variant' => null,
      'options' => array(),
      'locale_str' => null,
      'option_str' => null,
    );

    if(preg_match($localeRx, $identifier, $match)) {
      $localeData['language'] = $match['language'];
      if(!empty($match['script'])) {
        $localeData['script'] = $match['script'];
      }
      if(!empty($match['territory'])) {
        $localeData['territory'] = $match['territory'];
      }
      if(!empty($match['variant'])) {
        $localeData['variant'] = $match['variant'];
      }

      if(!empty($match['options'])) {
        $localeData['option_str'] = '@' . $match['options'];

        $options = explode(',', $match['options']);
        foreach($options as $option) {
          $optData = explode('=', $option, 2);
          $localeData['options'][$optData[0]] = $optData[1];
        }
      }

      $localeData['locale_str'] = substr($identifier, 0, strcspn($identifier, '@'));
    } else {
      throw new \Exception('Invalid locale identifier (' . $identifier . ') specified');
    }

    return $localeData;
  }

  public function loadAvailableLocales($locales) {
    $availables = [];
    foreach ($locales as $identifier => $description) {
      $availables[$identifier] = $this->parseLocaleIdentifier($identifier);
      $availables[$identifier]["description"] = $description;
    }
    $this->availableLocales = $availables;
  }

  public function __construct($config = array())
  {
    $this->config = $config;
    $this->translators = $config["translators"];

    //load available configs
    $this->loadAvailableLocales($config["locales"]);
    $this->setDefaultDomain($config["default_domain"]);
    $this->setDefaultTimeZone($config["default_timezone"]);

    if(!isset($config["default_locale"])) {
      throw new \Exception('Tried to use the translation system without a default locale and without a locale set');
    }
    $this->setLocale($config["default_locale"]);

    if($this->defaultTimeZone === null) {
      $this->defaultTimeZone = date_default_timezone_get();
    }

    if($this->defaultTimeZone === 'System/Localtime') {
      // http://trac.agavi.org/ticket/1008
      throw new \Exception("Your default timezone is 'System/Localtime', which likely means that you're running Debian, Ubuntu or some other Linux distribution that chose to include a useless and broken patch for system timezone database lookups into their PHP package, despite this very change being declined by the PHP development team for inclusion into PHP itself.\nThis pseudo-timezone, which is not defined in the standard 'tz' database used across many operating systems and applications, works for internal PHP classes and functions because the 'real' system timezone is resolved instead, but there is no way for an application to obtain the actual timezone name that 'System/Localtime' resolves to internally - information Agavi needs to perform accurate calculations and operations on dates and times.\n\nPlease set a correct timezone name (e.g. Europe/London) via 'date.timezone' in php.ini, use date_default_timezone_set() to set it in your code, or define a default timezone for Agavi to use in translation.xml. If you have some minutes to spare, file a bug report with your operating system vendor about this problem.\n\nIf you'd like to learn more about this issue, please refer to http://trac.agavi.org/ticket/1008");
    }
  }

  /**
   * Returns the list of available locales.
   *
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function getAvailableLocales()
  {
    return $this->availableLocales;
  }

  /**
   * Sets the current locale.
   *
   * @param      string The locale identifier.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function setLocale($identifier)
  {
    $this->currentLocaleIdentifier = $this->getLocaleIdentifier($identifier);
    $givenData = $this->parseLocaleIdentifier($identifier);
    $actualData = $this->parseLocaleIdentifier($this->currentLocaleIdentifier);
    // construct the given name from the locale from the closest match and the options that were given to the requested locale identifier
    $this->givenLocaleIdentifier = $actualData['locale_str'] . $givenData['option_str'];
  }

  /**
   * Retrieve the current locale.
   *
   * @return     AgaviLocale The current locale.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function getCurrentLocale()
  {
    $this->loadCurrentLocale();
    return $this->currentLocale;
  }

  /**
   * Retrieve the current locale identifier. This may not necessarily match
   * what has be given to setLocale() but instead the identifier of the closest
   * match from the available locales.
   *
   * @return     string The locale identifier.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function getCurrentLocaleIdentifier()
  {
    return $this->currentLocaleIdentifier;
  }

  /**
   * Retrieve the default locale.
   *
   * @return     AgaviLocale The current default.
   *
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function getDefaultLocale()
  {
    return $this->getLocale($this->getDefaultLocaleIdentifier());
  }

  /**
   * Retrieve the default locale identifier.
   *
   * @return     string The default locale identifier.
   *
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function getDefaultLocaleIdentifier()
  {
    return $this->defaultLocaleIdentifier;
  }

  /**
   * Sets the default domain.
   *
   * @param      string The new default domain.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function setDefaultDomain($domain)
  {
    $this->defaultDomain = $domain;
  }

  /**
   * Retrieve the default domain.
   *
   * @return     string The default domain.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function getDefaultDomain()
  {
    return $this->defaultDomain;
  }

  /**
   * Formats a date in the current locale.
   *
   * @param      mixed       The date to be formatted.
   * @param      string      The domain in which the date should be formatted.
   * @param      AgaviLocale The locale which should be used for formatting.
   *                         Defaults to the currently active locale.
   *
   * @return     string The formatted date.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function _d($date, $domain = null, $locale = null)
  {
    if($domain === null) {
      $domain = $this->defaultDomain;
    }

    if($locale === null) {
      $this->loadCurrentLocale();
    } elseif(is_string($locale)) {
      $locale = $this->getLocale($locale);
    }

    $domainExtra = '';
    $translator = $this->getTranslator($domain, $domainExtra, self::DATETIME);

    $retval = $translator->translate($date, $domainExtra, $locale);

    return $retval;
  }

  /**
   * Formats a currency amount in the current locale.
   *
   * @param      mixed       The number to be formatted.
   * @param      string      The domain in which the amount should be formatted.
   * @param      AgaviLocale The locale which should be used for formatting.
   *                         Defaults to the currently active locale.
   *
   * @return     string The formatted number.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function _c($number, $domain = null, $locale = null)
  {
    if($domain === null) {
      $domain = $this->defaultDomain;
    }

    if($locale === null) {
      $this->loadCurrentLocale();
    } elseif(is_string($locale)) {
      $locale = $this->getLocale($locale);
    }

    $domainExtra = '';
    $translator = $this->getTranslator($domain, $domainExtra, self::CURRENCY);

    $retval = $translator->translate($number, $domainExtra, $locale);

    return $retval;
  }

  /**
   * Formats a number in the current locale.
   *
   * @param      mixed       The number to be formatted.
   * @param      string      The domain in which the number should be formatted.
   * @param      AgaviLocale The locale which should be used for formatting.
   *                         Defaults to the currently active locale.
   *
   * @return     string The formatted number.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function _n($number, $domain = null, $locale = null)
  {
    if($domain === null) {
      $domain = $this->defaultDomain;
    }

    if($locale === null) {
      $this->loadCurrentLocale();
    } elseif(is_string($locale)) {
      $locale = $this->getLocale($locale);
    }

    $domainExtra = '';
    $translator = $this->getTranslator($domain, $domainExtra, self::NUMBER);

    $retval = $translator->translate($number, $domainExtra, $locale);

    return $retval;
  }

  /**
   * Translate a message into the current locale.
   *
   * @param      mixed       The message.
   * @param      string      The domain in which the translation should be done.
   * @param      AgaviLocale The locale which should be used for formatting.
   *                         Defaults to the currently active locale.
   * @param      array       The parameters which should be used for sprintf on
   *                         the translated string.
   *
   * @return     string The translated message.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function _($message, $domain = null, $locale = null, array $parameters = null)
  {
    if($domain === null) {
      $domain = $this->defaultDomain;
    }

    if($locale === null) {
      $this->loadCurrentLocale();
    } elseif(is_string($locale)) {
      $locale = $this->getLocale($locale);
    }

    $domainExtra = '';
    $translator = $this->getTranslator($domain, $domainExtra, self::MESSAGE);

    $retval = $translator->translate($message, $domainExtra, $locale);
    if(is_array($parameters)) {
      $retval = vsprintf($retval, $parameters);
    }

    return $retval;
  }

  /**
   * Translate a singular/plural message into the current locale.
   *
   * @param      string      The message for the singular form.
   * @param      string      The message for the plural form.
   * @param      int         The amount for which the translation should happen.
   * @param      string      The domain in which the translation should be done.
   * @param      AgaviLocale The locale which should be used for formatting.
   *                         Defaults to the currently active locale.
   * @param      array       The parameters which should be used for sprintf on
   *                         the translated string.
   *
   * @return     string The translated message.
   *
   * @author     David Zülke <dz@bitxtender.com>
   * @since      0.11.0
   */
  public function __($singularMessage, $pluralMessage, $amount, $domain = null, $locale = null, array $parameters = null)
  {
    return $this->_(array($singularMessage, $pluralMessage, $amount), $domain, $locale, $parameters);
  }

  protected function getTranslator(&$domain, &$domainExtra, $type = null)
  {
    $config = $this->config;
    if($domain[0] == '.') {
      $domain = $this->defaultDomain . $domain;
    }

    $domainParts = explode('.', $domain);

    do {
      if(count($domainParts) == 0) {
        throw new \InvalidArgumentException(sprintf('No translator exists for the domain "%s"', $domain));
      }
      $td = implode('.', $domainParts);
      array_pop($domainParts);
    } while(!isset($this->translators[$td]) || ($type && !isset($this->translators[$td][$type])));

    $domainExtra = substr($domain, strlen($td) + 1);
    $domain = $td;
    $translator = null;
    if ($type) {
      if (!isset($this->translators[$td][$type]["instance"])) {
        $this->translators[$td][$type]["instance"] = new $this->translators[$td][$type]["class"]($this, $config["translators"][$td][$type]);
      }
      $translator = $this->translators[$td][$type]["instance"];
    } else {
      if (!isset($this->translators[$td]["instance"])) {
        $this->translators[$td]["instance"] = new $this->translators[$td][$type]["class"]($this, $config["translators"][$td]);
      }
      $translator = $this->translators[$td]["instance"];
    }
    return $translator;
  }

  public function getDomainTranslator($domain, $type)
  {
    try {
      $domainExtra = '';
      return $this->getTranslators($domain, $domainExtra, $type);
    } catch(InvalidArgumentException $e) {
      return null;
    }
  }

  /**
   * Lazy loads the current locale if necessary.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  protected function loadCurrentLocale()
  {
    if(!isset($this->currentLocale) || !isset($this->currentLocale["identifier"]) || $this->currentLocale["identifier"] != $this->givenLocaleIdentifier) {
      $this->currentLocale = $this->getLocale($this->givenLocaleIdentifier);
    }
  }

  public function getMatchingLocaleIdentifiers($identifier)
  {
    // if a locale with the given identifier doesn't exist try to find the closest matches
    if(isset($this->availableLocales[$identifier])) {
      return array($identifier);
    }

    $idData = $this->parseLocaleIdentifier($identifier);

    $matchingLocaleIdentifiers = array();
    // iterate over all available locales
    foreach($this->availableLocales as $availableLocaleIdentifier => $availableLocale) {
      $matched = false;
      // iterate over possible properties to compare against (all given ones must match)
      foreach(array('language', 'script', 'territory', 'variant') as $propertyName) {
        // only perform check if property was in $identifier
        if(isset($idData[$propertyName])) {
          // compare against data in locale
          if($idData[$propertyName] == $availableLocale[$propertyName]) {
            // fine, continue with next
            $matched = true;
          } else {
            // failed, so we can bail out early and declare as non-matched
            $matched = false;
            break;
          }
        }
      }
      if($matched) {
        $matchingLocaleIdentifiers[] = $availableLocaleIdentifier;
      }
    }

    return $matchingLocaleIdentifiers;
  }

  public function getLocaleIdentifier($identifier)
  {
    if(isset($this->localeIdentifierCache[$identifier])) {
      return $this->localeIdentifierCache[$identifier];
    }

    $matchingLocaleIdentifiers = $this->getMatchingLocaleIdentifiers($identifier);

    switch(count($matchingLocaleIdentifiers)) {
      case 1:
        $availableLocaleIdentifier = current($matchingLocaleIdentifiers);
        break;
      case 0:
        throw new \Exception('Specified locale identifier ' . $identifier . ' which has no matching available locale defined');
      default:
        throw new \Exception('Specified ambiguous locale identifier ' . $identifier . ' which has matches: ' . implode(', ', $matchingLocaleIdentifiers));
    }

    return $this->localeIdentifierCache[$identifier] = $availableLocaleIdentifier;
  }

  public function getLocale($identifier, $forceNew = false)
  {
    // enable shortcut notation to only set options to the current locale
    if($identifier[0] == '@' && $this->currentLocaleIdentifier) {
      $idData = $this->parseLocaleIdentifier($this->currentLocaleIdentifier);
      $identifier = $idData['locale_str'] . $identifier;

      $newIdData = $this->parseLocaleIdentifier($identifier);
      $idData['options'] = array_merge($idData['options'], $newIdData['options']);
    } else {
      $idData = $this->parseLocaleIdentifier($identifier);
    }
    // this doesn't care about the options
    $availableLocale = $this->availableLocales[$this->getLocaleIdentifier($identifier)];

    // if the user wants all options reset he supplies an 'empty' option set (identifier ends with @)
    if(substr($identifier, -1) == '@') {
      $idData['options'] = array();
    } else {
      $idData['options'] = array_merge($availableLocale['options'], $idData['options']);
    }

    if(($atPos = strpos($identifier, '@')) !== false) {
      $identifier = $availableLocale['locale_str'] . substr($identifier, $atPos);
    } else {
      $identifier = $availableLocale['identifier'];
    }

    if(!$forceNew && isset($this->localeCache[$identifier])) {
      return $this->localeCache[$identifier];
    }

    $locale = $availableLocale;

    if(!$forceNew) {
      $this->localeCache[$identifier] = $locale;
    }

    return $locale;
  }

  public function setDefaultTimeZone($id)
  {
    $this->defaultTimeZone = $id;
  }

  public function getCurrentTimeZone()
  {
    return $this->getDefaultTimeZone();
  }

  public function getDefaultTimeZone()
  {
    return $this->defaultTimeZone;
  }
}