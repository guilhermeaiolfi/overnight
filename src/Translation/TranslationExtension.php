<?php

declare(strict_types=1);

namespace ON\Translation;

use ON\Application;
use ON\Config\ContainerConfig;
use ON\Extension\AbstractExtension;

class TranslationExtension extends AbstractExtension
{
	protected int $type = self::TYPE_EXTENSION;
	protected Application $app;
	protected array $options;
	protected array $configs = [];

	public function __construct(
		Application $app,
		array $options = []
	) {
		$this->options = $options;
		$this->app = $app;
	}

	public static function install(Application $app, ?array $options = []): mixed
	{
		$extension = new self($app, $options);
		$app->registerExtension('translation', $extension);

		return $extension;
	}

	public function setup(int $counter): bool
	{
		$config = $this->app->config;

		if (! isset($config)) {
			return false;
		}

		$containerConfig = $config->get(ContainerConfig::class);
		$containerConfig->mergeRecursiveDistinct("definitions.aliases", [
			TranslationManagerInterface::class => TranslationManagerFactory::class,
		]);

		$translationConfig = $config->get(TranslationConfig::class);
		$translationConfig->mergeRecursiveDistinct([
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
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%d/%m/%y',
					],
				],
				'event_date' => [
					'date' => [
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%d/%m/%y às %H:%M:%S',
					],
				],
				'short_month' => [
					'date' => [
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%b',
					],
				],
				'long_date' => [
					'date' => [
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%d de %B de %Y',
					],
				],
				'full_month' => [
					'date' => [
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%B',
					],
				],
				'default' => [
					'date' => [
						'name' => 'full',
						'class' => 'ON\Translation\DateFormatter',
						'format' => '%A, %%d de %B de %Y',
					],
					'cur' => [
						'name' => 'Currency Formatter',
						'class' => 'ON\Translation\CurrencyFormatter',
					],
				],
				'default.errors' => [
					'msg' => [
						'class' => 'ON\Translation\SimpleTranslator',
					],
				],
			],
		]);

		return true;
	}

	public function ready()
	{

	}
}
