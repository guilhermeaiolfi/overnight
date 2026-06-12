<?php

declare(strict_types=1);

namespace ON\RestApi\Hook;

final class RestHookTransaction
{
	/** @var list<RestHook> */
	private array $scheduled = [];

	public function __construct(
		private RestHookDispatcher $dispatcher,
	) {}

	/**
	 * @param object|\Closure(): ?object $payload
	 */
	public function schedule(string $slot, mixed $payload, bool $assertAuthorization = false): RestHook
	{
		if (! is_object($payload)) {
			throw new \InvalidArgumentException('Scheduled RestApi hook payload must be an object or Closure.');
		}

		$hook = new RestHook($slot, $payload, $assertAuthorization);
		$this->scheduled[] = $hook;

		return $hook;
	}

	public function flush(): void
	{
		foreach ($this->scheduled as $hook) {
			$payload = $hook->payload;
			if (is_callable($payload)) {
				$payload = $payload();
			}
			if ($payload === null) {
				continue;
			}

			$this->dispatcher->dispatch($payload->getCollection(), $hook->slot, $payload, $hook->assertAuthorization);
		}

		$this->scheduled = [];
	}

	public function rollback(): void
	{
		$this->scheduled = [];
	}
}
