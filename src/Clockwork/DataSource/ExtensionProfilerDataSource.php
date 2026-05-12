<?php

declare(strict_types=1);

namespace ON\Clockwork\DataSource;

use Clockwork\DataSource\DataSource;
use Clockwork\Request\Request;
use ON\Extension\ExtensionProfiler;

class ExtensionProfilerDataSource extends DataSource
{
	private bool $resolved = false;

	public function __construct(
		private ExtensionProfiler $profiler
	) {
	}

	public function resolve(Request $request)
	{
		if ($this->resolved || $this->profiler->samples() === []) {
			return $request;
		}

		$this->resolved = true;
		$this->addTimelineEvents($request);
		$this->addSummaryTable($request);

		return $request;
	}

	public function reset()
	{
		$this->resolved = false;
	}

	private function addTimelineEvents(Request $request): void
	{
		$i = 0;
		foreach ($this->profiler->extensionSummary() as $summary) {
			$start = max($summary['firstStart'], $request->time);
			$request->timeline()->create($this->shortClass($summary['extension']) . ' (total effective)', [
				'name' => 'overnight.extension.total.' . $i++,
				'start' => $start,
				'end' => $start + ($summary['total'] / 1000),
				'color' => 'purple',
				'data' => [
					'extension' => $summary['extension'],
					'calls' => $summary['calls'],
					'effective_duration_ms' => round($summary['total'], 3),
				],
			]);
		}

		foreach ($this->profiler->eventSummary() as $summary) {
			$start = max($summary['firstStart'], $request->time);
			$request->timeline()->create($this->shortClass($summary['extension']) . ' on ' . $summary['event'], [
				'name' => 'overnight.extension.event.' . $i++,
				'start' => $start,
				'end' => $start + ($summary['total'] / 1000),
				'color' => $summary['stage'] === 'event' ? 'blue' : 'green',
				'data' => [
					'extension' => $summary['extension'],
					'event' => $summary['event'],
					'stage' => $summary['stage'],
					'calls' => $summary['calls'],
					'effective_duration_ms' => round($summary['total'], 3),
				],
			]);
		}
	}

	private function addSummaryTable(Request $request): void
	{
		$userData = $request->userData('overnight.extensions')->title('Overnight Extensions');
		$totals = [];
		foreach ($this->profiler->extensionSummary() as $summary) {
			$totals[] = [
				'Extension' => $this->shortClass($summary['extension']),
				'Calls' => $summary['calls'],
				'Time(ms)' => number_format($summary['total'], 3),
			];
		}

		$events = [];
		foreach ($this->profiler->eventSummary() as $summary) {
			$events[] = [
				'Extension' => $this->shortClass($summary['extension']),
				'Stage' => $summary['stage'],
				'Event' => $summary['event'],
				'Calls' => $summary['calls'],
				'Time(ms)' => number_format($summary['total'], 3),
			];
		}

		$userData->counters([
			'Extensions' => count($totals),
			'Profiled calls' => count($this->profiler->samples()),
		]);
		$userData->table('Totals', $totals);
		$userData->table('Event contribution', $events);
	}

	private function shortClass(string $class): string
	{
		$parts = explode('\\', $class);

		return end($parts) ?: $class;
	}
}
