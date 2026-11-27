<?php

declare(strict_types=1);

use ON\Cache\Cache;
use ON\RateLimit\CacheRateLimiter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

require $argv[1];

$directory = $argv[2];
$marshaller = new class () extends DefaultMarshaller {
	public function unmarshall(string $value): mixed
	{
		// Keep the read open long enough for other workers to reach it.
		usleep(150_000);

		return parent::unmarshall($value);
	}
};
$adapter = new FilesystemAdapter('rate-limit', 0, $directory, $marshaller);
$limiter = new CacheRateLimiter((new Cache($adapter, true))->withNamespace('api'));

touch($directory . '/ready-' . $argv[3]);
while (! is_file($directory . '/start')) {
	usleep(1_000);
}

echo $limiter->attempt('client', 2)->isAllowed() ? 'allowed' : 'blocked';
