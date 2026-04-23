<?php

declare(strict_types=1);

namespace ON\Discovery;

use Psr\Container\ContainerInterface;

class DiscoveryCache
{
	protected array $adapters = []; 

	public function __construct(
		protected ContainerInterface $container
	) {

	}
	
	protected function getAdapter(DiscoveryLocation $location): mixed
	{
		$className = $location->strategy;
		if (!isset($this->adapters[$location->strategy])) {
			$instance = $this->container->get($className);
			$this->adapters[$className] = $instance;
		}
		return $this->adapters[$className];
	}

	public function save(DiscoverInterface $discover, DiscoveryLocation $location): bool
	{
		$adapter = $this->getAdapter($location);
		return $adapter->save($discover, $location);
	}

	public function clear(?DiscoverInterface $discover = null, ?DiscoveryLocation $location = null): void
	{
		if ($location === null) {
			return;
		}
		$adapter = $this->getAdapter($location);
		$adapter->clear($discover, $location);
	}

	public function recover(DiscoverInterface $discover, DiscoveryLocation $location): DiscoverInterface
	{
		$adapter = $this->getAdapter($location);
		return $adapter->recover($discover, $location);
	}

	/**
	 * It updates all discovers together because it's more efficiente
	 * Otherwise, we would have to scan the filesystem as many times as there were discovers.
	 */
	public function update(array $discovers, DiscoveryLocation $location): void
	{
		$adapter = $this->getAdapter($location);
		$adapter->update($discovers, $location);
	}
}
