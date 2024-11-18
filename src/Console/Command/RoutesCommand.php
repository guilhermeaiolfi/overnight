<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ON\Console\Command;

use ON\Application;
use ON\Router\RouterConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RoutesCommand extends Command
{
	public function __construct(
		protected Application $app
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->ignoreValidationErrors();

		$this
			->setName('router:list')
			->setDefinition([
				new InputArgument('command_name', InputArgument::OPTIONAL, 'The command name', 'help', fn () => array_keys((new ApplicationDescription($this->getApplication()))->getCommands())),
				new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt', fn () => (new DescriptorHelper())->getFormats()),
				new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command help'),
			])
			->setDescription('Clear Cache');
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{

		$table = new Table($output);

		$routerCfg = $this->app->container->get(RouterConfig::class);
		$collection = $routerCfg->getRoutesToInject();
		$routes = [];
		foreach ($collection as $route) {
			$routes[] = [
				$route->getPath(),
				$route->getMiddleware(),
				$route->getName(),
			];
		}

		$table
			->setHeaders(['Path', 'Middleware', 'Name'])
			->setRows($routes)
		;
		$table->render();

		return Command::SUCCESS;
	}
}
