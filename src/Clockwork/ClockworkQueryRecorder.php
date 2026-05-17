<?php

declare(strict_types=1);

namespace ON\Clockwork;

use function clock;
use Clockwork\Helpers\Serializer;
use Clockwork\Helpers\StackFilter;
use Clockwork\Helpers\StackTrace;
use Clockwork\Support\Vanilla\Clockwork;

class ClockworkQueryRecorder
{
	public static function record(string $query, ?array $parameters = null, float $duration = 0.0): void
	{
		if (! function_exists('clock')) {
			return;
		}

		$clockwork = clock();
		if (! $clockwork instanceof Clockwork) {
			return;
		}

		$filter = StackFilter::make()
			->isNotVendor([ 'itsgoingd', 'guilhermeaiolfi', 'league' ])
			->isNotNamespace([ 'Clockwork', 'League', 'Invoker' ])
			->isNotFunction([ 'profileCall', 'emitEvent' ]);

		$trace = StackTrace::get()->resolveViewName()->skip($filter);

		$clockwork->addDatabaseQuery(
			$query,
			$parameters,
			(int) floor($duration * 1000),
			[
				'trace' => (new Serializer())->trace($trace),
			]
		);
	}
}
