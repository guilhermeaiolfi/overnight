<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Config\Config;

class TranslationConfig extends Config
{
	public static function getDefaults(): array
	{
		return [
			'default_locale' => 'en_US@currency=USD',
			"default_domain" => 'default',
			'default_timezone' => null,
			'locales' => [
				'pt_BR' => 'Português',
				'en_US' => 'English',
			],
			'translators' => [
				'short_date' => [
					'date' => [
						'class' => DateFormatter::class,
						'format' => 'dd/MM/y', //'/%m/%y',
					],
				],
				'event_date' => [
					'date' => [
						'class' => DateFormatter::class,
						'format' => 'dd/MM/y \'às\' HH:mm:ss', //%d/%m/%y às %H:%M:%S',
					],
				],
				'short_month' => [
					'date' => [
						'class' => DateFormatter::class,
						'format' => 'MMM', //'%b',
					],
				],
				'long_date' => [
					'date' => [
						'class' => DateFormatter::class,
						'format' => 'dd \'de\' MMMM \'de\' y', //'%d de %B de %Y',
					],
				],
				'full_month' => [
					'date' => [
						'class' => DateFormatter::class,
						'format' => 'MMMM', //'%B',
					],
				],
				'default' => [
					'date' => [
						'name' => 'full',
						'class' => DateFormatter::class,
						'format' => 'EEEE, dd \'de\' MMMM \'de\' y', //'%A, %%d de %B de %Y',
					],
					'cur' => [
						'name' => 'Currency Formatter',
						'class' => CurrencyFormatter::class,
					],
				],
				'default.errors' => [
					'msg' => [
						'class' => 'ON\Translation\SimpleTranslator',
					],
				],
			],
		];
	}
}
