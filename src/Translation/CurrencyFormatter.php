<?php
namespace ON\Translation;

use ON\Translation\TranslationManagerInterface;

class CurrencyFormatter implements TranslatorInterface
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
    $type = 'cur';
    $this->translationManager = $tm;

    //$this->format = $parameters["format"];

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
      $message = 0.00;
    }
    if (is_string($message)) {
      $message = (float) $message;
    }
    // TODO: obvioulsy this needs to be reimplemented to support locales
    return 'R$ ' . number_format($message, 2, ",", "."); //$this->moneyFormat("%i", $message);
  }

  // good function, except it returns BRL for R$ (brazilian portuguese)
  public function moneyFormat($format, $number)
  {
    $regex  = '/%((?:[\^!\-]|\+|\(|\=.)*)([0-9]+)?'.
              '(?:#([0-9]+))?(?:\.([0-9]+))?([in%])/';
    if (setlocale(LC_MONETARY, 0) == 'C') {
        setlocale(LC_MONETARY, '');
    }
    $locale = localeconv();
    preg_match_all($regex, $format, $matches, PREG_SET_ORDER);
    foreach ($matches as $fmatch) {
        $value = floatval($number);
        $flags = array(
            'fillchar'  => preg_match('/\=(.)/', $fmatch[1], $match) ?
                           $match[1] : ' ',
            'nogroup'   => preg_match('/\^/', $fmatch[1]) > 0,
            'usesignal' => preg_match('/\+|\(/', $fmatch[1], $match) ?
                           $match[0] : '+',
            'nosimbol'  => preg_match('/\!/', $fmatch[1]) > 0,
            'isleft'    => preg_match('/\-/', $fmatch[1]) > 0
        );
        $width      = trim($fmatch[2]) ? (int)$fmatch[2] : 0;
        $left       = trim($fmatch[3]) ? (int)$fmatch[3] : 0;
        $right      = trim($fmatch[4]) ? (int)$fmatch[4] : $locale['int_frac_digits'];
        $conversion = $fmatch[5];

        $positive = true;
        if ($value < 0) {
            $positive = false;
            $value  *= -1;
        }
        $letter = $positive ? 'p' : 'n';

        $prefix = $suffix = $cprefix = $csuffix = $signal = '';

        $signal = $positive ? $locale['positive_sign'] : $locale['negative_sign'];
        switch (true) {
            case $locale["{$letter}_sign_posn"] == 1 && $flags['usesignal'] == '+':
                $prefix = $signal;
                break;
            case $locale["{$letter}_sign_posn"] == 2 && $flags['usesignal'] == '+':
                $suffix = $signal;
                break;
            case $locale["{$letter}_sign_posn"] == 3 && $flags['usesignal'] == '+':
                $cprefix = $signal;
                break;
            case $locale["{$letter}_sign_posn"] == 4 && $flags['usesignal'] == '+':
                $csuffix = $signal;
                break;
            case $flags['usesignal'] == '(':
            case $locale["{$letter}_sign_posn"] == 0:
                $prefix = '(';
                $suffix = ')';
                break;
        }
        if (!$flags['nosimbol']) {
            $currency = $cprefix .
                        ($conversion == 'i' ? $locale['int_curr_symbol'] : $locale['currency_symbol']) .
                        $csuffix;
        } else {
            $currency = '';
        }
        $space  = $locale["{$letter}_sep_by_space"] ? ' ' : '';

        $value = number_format($value, $right, $locale['mon_decimal_point'],
                 $flags['nogroup'] ? '' : $locale['mon_thousands_sep']);
        $value = @explode($locale['mon_decimal_point'], $value);

        $n = strlen($prefix) + strlen($currency) + strlen($value[0]);
        if ($left > 0 && $left > $n) {
            $value[0] = str_repeat($flags['fillchar'], $left - $n) . $value[0];
        }
        $value = implode($locale['mon_decimal_point'], $value);
        if ($locale["{$letter}_cs_precedes"]) {
            $value = $prefix . $currency . $space . $value . $suffix;
        } else {
            $value = $prefix . $value . $space . $currency . $suffix;
        }
        if ($width > 0) {
            $value = str_pad($value, $width, $flags['fillchar'], $flags['isleft'] ?
                     STR_PAD_RIGHT : STR_PAD_LEFT);
        }

        $format = str_replace($fmatch[0], $value, $format);
    }
    return $format;
  }
}