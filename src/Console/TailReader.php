<?php

declare(strict_types=1);

namespace ON\Console;

use Closure;
use Fiber;
use RuntimeException;

readonly class TailReader
{
	public function tail(string $path, ?Closure $format = null): void
	{
		$format ??= fn (string $text) => $text;

		$handle = @fopen($path, "r");
		if ($handle === false) {
			throw new RuntimeException(sprintf('Unable to open file "%s" for reading.', $path));
		}

		try {
			fseek($handle, 0, SEEK_END);
			$offset = ftell($handle);
			if ($offset === false) {
				$offset = 0;
			}

			/** @phpstan-ignore-next-line */
			while (true) {
				if (Fiber::getCurrent() !== null) {
					Fiber::suspend();
				}

				clearstatcache(true, $path);
				fseek($handle, 0, SEEK_END);
				$newOffset = ftell($handle);

				if ($newOffset === false || $newOffset <= $offset) {
					if ($newOffset !== false && $newOffset < $offset) {
						$offset = $newOffset;
					}
					usleep(100_000);

					continue;
				}

				fseek($handle, $offset);

				$chunk = fread($handle, $newOffset - $offset);
				if ($chunk !== false) {
					$output = ltrim($chunk);
					if ($output !== '') {
						fwrite(STDOUT, $format($output) . PHP_EOL);
					}
				}

				$offset = $newOffset;
				usleep(100_000);
			}
		} finally {
			fclose($handle);
		}
	}
}
