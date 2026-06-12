<?php

declare(strict_types=1);

namespace Benchmarks\ON;

use Benchmarks\ON\Support\BootstrapProject;

/**
 * Bootstrap-focused framework benchmarks.
 *
 * @Revs(5)
 * @Iterations(3)
 * @Warmup(1)
 */
final class BootstrapBench
{
	private ?BootstrapProject $project = null;
	/** @var list<BootstrapProject> */
	private array $projectPool = [];
	private int $projectIndex = 0;

	/**
	 * @BeforeMethods({"setUpBareDebug"})
	 * @AfterMethods({"tearDownProject"})
	 */
	public function benchBareApplicationDebug(): void
	{
		$app = $this->project?->bootstrapBare(true);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * @BeforeMethods({"setUpCoreDebug"})
	 * @AfterMethods({"tearDownProject"})
	 */
	public function benchCoreExtensionsDebug(): void
	{
		$app = $this->project?->bootstrapCore(true);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * A cold bootstrap should rebuild its cache on each measured iteration.
	 *
	 * @BeforeMethods({"setUpCoreProductionCold"})
	 * @AfterMethods({"tearDownProject"})
	 * @Revs(1)
	 */
	public function benchCoreExtensionsProductionCold(): void
	{
		$app = $this->project?->bootstrapCore(false);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * @BeforeMethods({"setUpCoreProductionWarm"})
	 * @AfterMethods({"tearDownProject"})
	 */
	public function benchCoreExtensionsProductionWarmCaches(): void
	{
		$app = $this->project?->bootstrapCore(false);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * @BeforeMethods({"setUpProductionDebug"})
	 * @AfterMethods({"tearDownProject"})
	 */
	public function benchProductionStackDebug(): void
	{
		$project = $this->nextProjectFromPool();
		$app = $project?->bootstrapProduction(true);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * A cold bootstrap should rebuild its cache on each measured iteration.
	 *
	 * @BeforeMethods({"setUpProductionCold"})
	 * @AfterMethods({"tearDownProject"})
	 * @Revs(1)
	 */
	public function benchProductionStackProductionCold(): void
	{
		$project = $this->nextProjectFromPool();
		$app = $project?->bootstrapProduction(false);
		$app?->getInstalledExtensions();
		unset($app);
	}

	/**
	 * @BeforeMethods({"setUpProductionWarm"})
	 * @AfterMethods({"tearDownProject"})
	 */
	public function benchProductionStackProductionWarmCaches(): void
	{
		$project = $this->nextProjectFromPool();
		$app = $project?->bootstrapProduction(false);
		$app?->getInstalledExtensions();
		unset($app);
	}

	public function setUpBareDebug(): void
	{
		$this->project = BootstrapProject::createBare(true);
	}

	public function setUpCoreDebug(): void
	{
		$this->project = BootstrapProject::createCore(true, false);
	}

	public function setUpCoreProductionCold(): void
	{
		$this->project = BootstrapProject::createCore(false, true);
	}

	public function setUpCoreProductionWarm(): void
	{
		$this->project = BootstrapProject::createCore(false, true);
		$this->project->warmCoreCaches();
	}

	public function setUpProductionDebug(): void
	{
		$this->primeProjectPool(
			static fn (): BootstrapProject => BootstrapProject::createProduction(true, false)
		);
	}

	public function setUpProductionCold(): void
	{
		$this->primeProjectPool(
			static fn (): BootstrapProject => BootstrapProject::createProduction(false, true)
		);
	}

	public function setUpProductionWarm(): void
	{
		$this->primeProjectPool(function (): BootstrapProject {
			$project = BootstrapProject::createProduction(false, true);
			$project->warmProductionCaches();

			return $project;
		});
	}

	public function tearDownProject(): void
	{
		$this->project?->destroy();
		$this->project = null;
		foreach ($this->projectPool as $project) {
			$project->destroy();
		}
		$this->projectPool = [];
		$this->projectIndex = 0;
	}

	private function primeProjectPool(callable $factory, int $count = 48): void
	{
		$this->projectPool = [];
		$this->projectIndex = 0;

		for ($index = 0; $index < $count; $index++) {
			$this->projectPool[] = $factory();
		}
	}

	private function nextProjectFromPool(): ?BootstrapProject
	{
		if (! isset($this->projectPool[$this->projectIndex])) {
			return null;
		}

		return $this->projectPool[$this->projectIndex++];
	}
}
