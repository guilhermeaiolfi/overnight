<?php

declare(strict_types=1);

namespace ON\Extension;

use Closure;
use ReflectionFunction;

class ExtensionProfiler
{
	/** @var array<int, array{extension: string, event: string, stage: string, listener: string, start: float, end: float, duration: float}> */
	private array $samples = [];

	/** @var string[] */
	private array $activeExtensions = [];

	/** @var array<int, array{extension: string, event: string, stage: string, listener: string, start: float, startNs: int, childDuration: float}> */
	private array $activeFrames = [];

	public function __construct(
		private bool $enabled = true
	) {
	}

	public function setEnabled(bool $enabled): void
	{
		$this->enabled = $enabled;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function begin(string $extension, string $event, string $stage, mixed $listener = null): void
	{
		if (! $this->enabled) {
			return;
		}

		$this->activeExtensions[] = $extension;
		$this->activeFrames[] = [
			'extension' => $extension,
			'event' => $event,
			'stage' => $stage,
			'listener' => $listener === null ? $stage : $this->describeCallable($listener),
			'start' => microtime(true),
			'startNs' => hrtime(true),
			'childDuration' => 0.0,
		];
	}

	public function end(): void
	{
		if (! $this->enabled || $this->activeFrames === []) {
			return;
		}

		$frame = array_pop($this->activeFrames);
		$totalDuration = (hrtime(true) - $frame['startNs']) / 1_000_000;
		$duration = max(0.0, $totalDuration - $frame['childDuration']);
		$this->samples[] = [
			'extension' => $frame['extension'],
			'event' => $frame['event'],
			'stage' => $frame['stage'],
			'listener' => $frame['listener'],
			'start' => $frame['start'],
			'end' => $frame['start'] + ($duration / 1000),
			'duration' => $duration,
		];
		array_pop($this->activeExtensions);

		$parent = array_key_last($this->activeFrames);
		if ($parent !== null) {
			$this->activeFrames[$parent]['childDuration'] += $totalDuration;
		}
	}

	public function getActiveExtension(): ?string
	{
		if ($this->activeExtensions === []) {
			return null;
		}

		return $this->activeExtensions[array_key_last($this->activeExtensions)];
	}

	/**
	 * @return array<int, array{extension: string, event: string, stage: string, listener: string, start: float, end: float, duration: float}>
	 */
	public function samples(): array
	{
		return $this->samples;
	}

	/**
	 * @return array<string, array{extension: string, calls: int, total: float, firstStart: float}>
	 */
	public function extensionSummary(): array
	{
		$summary = [];

		foreach ($this->samples as $sample) {
			$extension = $sample['extension'];
			$summary[$extension] ??= [
				'extension' => $extension,
				'calls' => 0,
				'total' => 0.0,
				'firstStart' => $sample['start'],
			];

			$summary[$extension]['calls']++;
			$summary[$extension]['total'] += $sample['duration'];
			$summary[$extension]['firstStart'] = min($summary[$extension]['firstStart'], $sample['start']);
		}

		uasort($summary, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

		return $summary;
	}

	/**
	 * @return array<string, array{extension: string, event: string, stage: string, calls: int, total: float, firstStart: float}>
	 */
	public function eventSummary(): array
	{
		$summary = [];

		foreach ($this->samples as $sample) {
			$key = $sample['extension'] . "\0" . $sample['stage'] . "\0" . $sample['event'];
			$summary[$key] ??= [
				'extension' => $sample['extension'],
				'event' => $sample['event'],
				'stage' => $sample['stage'],
				'calls' => 0,
				'total' => 0.0,
				'firstStart' => $sample['start'],
			];

			$summary[$key]['calls']++;
			$summary[$key]['total'] += $sample['duration'];
			$summary[$key]['firstStart'] = min($summary[$key]['firstStart'], $sample['start']);
		}

		uasort($summary, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

		return $summary;
	}

	private function describeCallable(mixed $callable): string
	{
		if (is_array($callable)) {
			[$target, $method] = $callable;
			$class = is_object($target) ? $target::class : (string) $target;

			return $class . '::' . $method;
		}

		if (is_string($callable)) {
			return $callable;
		}

		if ($callable instanceof Closure) {
			$reflection = new ReflectionFunction($callable);
			$file = $reflection->getFileName();
			$line = $reflection->getStartLine();

			return 'Closure' . ($file ? "({$file}:{$line})" : '');
		}

		if (is_object($callable)) {
			return $callable::class . '::__invoke';
		}

		return 'callable';
	}
}
